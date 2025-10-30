from celery import Celery
from celery.schedules import crontab
import os
from dotenv import load_dotenv
from redis_manager import RedisManager
import json
import requests

import logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

load_dotenv()

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
    logger.info("=" * 60)
    logger.info(f"Processing ML task for user_id: {job_data.get('user_id')}")

    try:
        user_id = job_data['user_id']

        # Prepare request body for Flask API
        api_payload = {
            'user_id': user_id,
            'features': job_data['features'],
            'recent_records': job_data['recent_records']
        }

        # Get Flask API URL from environment
        flask_url = os.getenv('FLASK_API_URL', 'http://localhost:5000')
        api_endpoint = f"{flask_url}/get-recommendation"

        logger.info(f"Calling Flask API: {api_endpoint}")

        # Call Flask API
        response = requests.post(
            api_endpoint,
            json=api_payload,
            headers={'Content-Type': 'application/json'},
            timeout=300  # 5 minutes timeout
        )

        response.raise_for_status()
        result_data = response.json()

        logger.info(f"✓ Received response from Flask API")

        logger.info(f"✓ Got {len(result_data['foods'])} recommendations")

        # Store results in Redis (expires in 1 hour)
        result_key = f"recommendation:{user_id}"
        r.setex(result_key, 3600, json.dumps(result_data))
        logger.info(f"✓ Results stored in Redis: {result_key}")
        logger.info("=" * 60)

        return {
            'status': 'success',
            'user_id': user_id,
            'recommendations_count': len(result_data['foods'])
        }

    except requests.exceptions.RequestException as e:
        logger.error(f"Error calling Flask API: {str(e)}", exc_info=True)

        # Store error in Redis
        error_key = f"recommendation:{job_data.get('result_user_id')}"
        r.setex(error_key, 3600, json.dumps({
            'status': 'failed',
            'error': f"Flask API error: {str(e)}"
        }))

        raise

    except Exception as e:
        logger.error(f"Error processing ML task: {str(e)}", exc_info=True)

        # Store error in Redis
        error_key = f"recommendation:{job_data.get('result_user_id')}"
        r.setex(error_key, 3600, json.dumps({
            'status': 'failed',
            'error': str(e)
        }))

        raise

# Celery Beat schedule
# celery_app.conf.beat_schedule = {
#     'weekly-retrain': {
#         'task': 'tasks.scheduled_retrain_all',
#         'schedule': crontab(hour=2, minute=0, day_of_week=0),
#     },
# }