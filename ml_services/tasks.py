from celery import Celery
from celery.schedules import crontab
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


import logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

load_dotenv()

# Load model at startup
MODEL_DIR = Path(__file__).parent / 'hierarchial_model'
sys.path.insert(0, str(MODEL_DIR))
model_file = MODEL_DIR / 'hierarchical_model.pkl'
food_rec_file = MODEL_DIR / 'food_recommendations.json'
model_metadata_file = MODEL_DIR / 'model_metadata.json'
model_package, recommendations = load_baseline_model()
wma_recommender = WMARecommender(model_package, recommendations, decay_factor=0.9)
# Load model and metadata
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

# Initialize Celery
celery_app = Celery(
    'ml_tasks',
    broker=os.getenv('CELERY_BROKER', 'redis://localhost:6379/0'),
    backend=os.getenv('CELERY_BACKEND', 'redis://localhost:6379/0')
)

celery_app.conf.update(
    task_serializer='json',
    result_serializer='json',
    accept_content=['json'],
    timezone='Asia/Jakarta',
    enable_utc=True,
    task_track_started=True,
    task_time_limit=600,  # 10 minutes max
)

# Initialize managers
redis_mgr = RedisManager()
r = redis_mgr.client

RETRAIN_THRESHOLD = 30

@celery_app.task(name='tasks.process_ml_recommendation')
def process_ml_recommendation(job_data):
    """
    Process ML recommendation task
    
    Args:
        job_data: Dictionary containing user_id, features, and recent_records
    """
    logger.info("=" * 60)
    logger.info(f"Processing ML task for user_id: {job_data.get('user_id')}")
    
    try:
        user_id = job_data['user_id']
        features = job_data['features']
        recent_foods = job_data['recent_records']
        
        # Build features array
        features_array = np.array([[
            features['age'],
            features['gender'],
            features['activity'],
            features['bmi'],
            features['carb_pct'],
            features['protein_pct'],
            features['fat_pct']
        ]])
        
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
        
        # Store results in Redis (expires in 1 hour)
        result_key = f"recommendation:{user_id}"
        result_data = recommendations
        
        r.setex(result_key, 3600, json.dumps(result_data))
        logger.info(f"✓ Results stored: {result_key}")
        logger.info("=" * 60)
        
        return {
            'status': 'success',
            'user_id': user_id,
            'recommendations_count': len(recommendations)
        }
        
    except Exception as e:
        logger.error(f"Error processing ML task: {str(e)}", exc_info=True)
        
        # Store error in Redis
        error_key = f"ml_result:{job_data.get('user_id')}"
        r.setex(error_key, 3600, json.dumps({
            'status': 'failed',
            'error': str(e)
        }))
        
        raise

# Celery Beat schedule
celery_app.conf.beat_schedule = {
    'weekly-retrain': {
        'task': 'tasks.scheduled_retrain_all',
        'schedule': crontab(hour=2, minute=0, day_of_week=0),
    },
}