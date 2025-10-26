from flask import Flask, request, jsonify
from flask_cors import CORS
from dotenv import load_dotenv
import os
from redis_manager import RedisManager
import json
import numpy as np
import logging
from flask_executor import Executor
import os
from dotenv import load_dotenv
from redis_manager import RedisManager
import pickle
import json
import numpy as np
import os
from pathlib import Path
from wma_recommendation import WMARecommender, load_baseline_model, load_food_database

# Load model at startup
model_package, recommendations = load_baseline_model()
food_macros = load_food_database('food_database.csv')
wma_recommender = WMARecommender(model_package, recommendations, food_macros)

scaler = model_package['scaler']
kmeans = model_package['model']
feature_cols = model_package['feature_cols']
user_profiles = model_package['user_profiles']

X_scaled_train = scaler.transform(
    user_profiles[feature_cols]
)
train_labels = user_profiles['cluster'].values



logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

load_dotenv()

app = Flask(__name__)
CORS(app)

# Initialize Redis
redis_mgr = RedisManager()
r = redis_mgr.client


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
        
def build_features_array(features):
    """
    Build features array in the correct order matching the model's feature_cols.
    Order must match kmeans_model.py: ['age', 'bmi', 'activity', 'gender', 'carb_pct', 'protein_pct', 'fat_pct']

    Args:
        features: dict with user feature values

    Returns:
        np.array: 2D array with shape (1, 7) containing features in correct order
    """
    return np.array([[
        features['age'],
        features['bmi'],
        features['activity'],
        features['gender'],
        features['carb_pct'],
        features['protein_pct'],
        features['fat_pct']
    ]])

@app.route('/get-recommendation', methods=['POST'])
def predict_cluster():
    """Predict user cluster and return food recommendations"""
    try:
        data = request.json
        user_id = data['user_id']
        features = data['features']
        recent_foods = data['recent_records']

        if len(recent_foods) == 30:
            return retrain_model(features, recent_foods, user_id)
        else:
            return assign_cluster(features, user_id)


    except Exception as e:
        logger.error(f"Error in predict_cluster: {str(e)}")
        return jsonify({'error': str(e)}), 400

def assign_cluster(features, user_id):
    features_array = build_features_array(features)
    # Scale features
    features_scaled = scaler.transform(features_array)
    
    # Find nearest neighbor
    distances = np.linalg.norm(X_scaled_train - features_scaled, axis=1)
    nearest_idx = np.argmin(distances)
    cluster = int(train_labels[nearest_idx])
    
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
    features_array = build_features_array(features)
    
    logger.info(f"Features Array: {features_array}")
    
    
    # Process recommendations
    cluster_id = wma_recommender.get_user_cluster(features_array)
    user_foods = recent_foods

    recommendations = wma_recommender.get_recommendations(
        user_features=features_array,
        user_foods=user_foods,
        cluster_id=cluster_id,
        top_n=30
    )


    logger.info(f"✓ Generated {len(recommendations)} recommendations")
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

    # return jsonify({
    #     'user_id': user_id,
    #     'recommendations': recommendations
    # }), 200
    


if __name__ == '__main__':
    port = int(os.getenv('FLASK_PORT', 5000))
    app.run(host='0.0.0.0', port=port, debug=False)