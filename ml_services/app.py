from flask import Flask, request, jsonify
from flask_cors import CORS
from dotenv import load_dotenv
import os
from redis_manager import RedisManager
import numpy as np
import pandas as pd
import logging
from wma_recommendation import WMARecommender, load_baseline_model, load_food_database

# Load model at startup
model_package, recommendations = load_baseline_model()
food_macros = load_food_database('food_database.csv')
wma_recommender = WMARecommender(model_package, recommendations, food_macros)

scaler = model_package['scaler']
kmeans = model_package['model']
feature_cols = model_package['feature_cols']
user_profiles = model_package['user_profiles']

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

load_dotenv()

app = Flask(__name__)
CORS(app)

# Initialize Redis
redis_mgr = RedisManager()
r = redis_mgr.client

def predict_user_cluster(user_data, scaler, kmeans):

    # Scale features using training scaler
    features_scaled = scaler.transform(user_data)

    # K-Means can predict directly (finds nearest cluster center)
    cluster = kmeans.predict(features_scaled)[0]

    return int(cluster)


@app.route('/health', methods=['GET'])
def health_check():
    """Health check endpoint"""
    try:
        redis_mgr.client.ping()
        return jsonify({
            'status': 'healthy',
            'service': 'ml-service',
            'redis': 'connected'
        }), 200
    except Exception as e:
        return jsonify({
            'status': 'unhealthy',
            'error': str(e)
        }), 503
        
def build_demographic_features_array(features):
    return np.array([[
        features['activity'],
        features['bmi']
    ]])

def build_nutrition_features_dict(features):
    try:
        if 'carb_pct' in features and 'protein_pct' in features and 'fat_pct' in features:
            return pd.DataFrame([{
                'carb_pct': features['carb_pct'],
                'protein_pct': features['protein_pct'],
                'fat_pct': features['fat_pct']
            }])
        else:
            logger.warning("Macro features not available, WMA will skip nutrition scoring")
            return pd.DataFrame([{}])  # Empty DataFrame for WMA to handle gracefully
    except Exception as e:
        logger.error(f"Error building nutrition features: {str(e)}")
        return pd.DataFrame([{}])

@app.route('/get-recommendation', methods=['POST'])
def predict_cluster():
    """Predict user cluster and return food recommendations"""
    try:
        data = request.json
        user_id = data['user_id']
        features = data['features']
        recent_foods = data['recent_records']

        if len(recent_foods) >= 30:
            return retrain_model(features, recent_foods, user_id)
        else:
            return assign_cluster(features, user_id)


    except Exception as e:
        logger.error(f"Error in predict_cluster: {str(e)}")
        return jsonify({'error': str(e)}), 400

def assign_cluster(features, user_id):
    demographic_features = build_demographic_features_array(features)
    # Predict cluster using K-Means model
    cluster = predict_user_cluster(demographic_features, scaler, kmeans)
    logger.info(f"Cold-start user assigned to cluster {cluster} (activity={features['activity']}, bmi={features['bmi']:.1f})")
    # Get recommendations
    cluster_foods = recommendations[f'cluster_{cluster}']
    send_data = []
    for food in cluster_foods:
        send_data.append({
            'user_id': user_id,
            'food_id': food['food_id'],
            'recommendation_score': food['recommendation_score']
        })
    return jsonify({
        'foods': send_data,
    }), 200

def retrain_model(features, recent_foods, user_id):
    # Build demographic features for cluster assignment
    demographic_features = build_demographic_features_array(features)

    # Build nutrition features for WMA nutrition scoring
    nutrition_features = build_nutrition_features_dict(features)

    # Get user's demographic cluster (uses activity + bmi)
    cluster_id = wma_recommender.get_user_cluster(demographic_features)
    logger.info(f"User assigned to cluster {cluster_id}")

    # Get WMA recommendations (uses nutrition features for scoring)
    recommendations = wma_recommender.get_recommendations(
        user_features=nutrition_features,  # Nutrition features for macro-based scoring
        user_foods=recent_foods,
        cluster_id=cluster_id,
        top_n=30
    )

    logger.info(f"✓ Generated {len(recommendations)} WMA recommendations")
    send_data = []
    for food in recommendations:
        send_data.append({
            'user_id': user_id,
            'food_id': food['food_id'],
            'recommendation_score': food['wma_score']
        })

    return jsonify({
        'foods': send_data,
    }), 200
    


if __name__ == '__main__':
    port = int(os.getenv('FLASK_PORT', 5000))
    app.run(host='0.0.0.0', port=port, debug=False)