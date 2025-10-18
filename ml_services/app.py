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
import sys
import os
from pathlib import Path
from wma_recommendation import WMARecommender, load_baseline_model


# Load model at startup
model_package, recommendations = load_baseline_model()
wma_recommender = WMARecommender(model_package, recommendations, decay_factor=0.9)



# Load model and metadata
MODEL_DIR = Path(__file__).parent / 'hierarchial_model'
sys.path.insert(0, str(MODEL_DIR))
model_file = MODEL_DIR / 'hierarchical_model.pkl'
food_rec_file = MODEL_DIR / 'food_recommendations.json'
model_metadata_file = MODEL_DIR / 'model_metadata.json'
with open(model_file, 'rb') as f:
    model_package = pickle.load(f)

with open(model_metadata_file, 'r') as f:
    metadata = json.load(f)

with open(food_rec_file, 'r') as f:
    recommendations = json.load(f)

scaler = model_package['scaler']
hierarchical = model_package['model']
X_scaled_train = model_package['scaler'].transform(
    model_package['user_profiles'][metadata['feature_cols']].fillna(
        model_package['user_profiles'][metadata['feature_cols']].median()
    )
)
train_labels = model_package['user_profiles']['cluster'].values
feature_cols = metadata['feature_cols']

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
        
@app.route('/predict-cluster', methods=['POST'])
def predict_cluster():
    """Predict user cluster and return food recommendations"""
    try:
        data = request.json
        user_id = data['user_id']
        features = data['features']
        
        # Extract features in correct order from the nested dict
        features_array = np.array([[
            features['age'],
            features['bmi'],
            features['activity'],
            features['gender'],
            features['carb_pct'],
            features['protein_pct'],
            features['fat_pct']
        ]])
    
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
        
    except Exception as e:
        logger.error(f"Error in predict_cluster: {str(e)}")
        return jsonify({'error': str(e)}), 400


if __name__ == '__main__':
    port = int(os.getenv('FLASK_PORT', 5000))
    app.run(host='0.0.0.0', port=port, debug=False)