# ============================================================================
# app.py - Flask API
# ============================================================================

from flask import Flask, request, jsonify
from flask_cors import CORS
import redis
import json
import os
from dotenv import load_dotenv
from datetime import datetime

# Import Celery tasks
# from tasks import train_user_model

load_dotenv()

app = Flask(__name__)
CORS(app)
redis_client = redis.Redis(
    host=os.getenv('REDIS_HOST', 'redis'),
    port=int(os.getenv('REDIS_PORT', 6379)),
    decode_responses=True
)
# # Redis connection
# redis_client = redis.Redis.from_url(
#     os.getenv('REDIS_URL', 'redis://localhost:6379/6379'),
#     decode_responses=True
# )

INITIAL_THRESHOLD = 10
RETRAIN_THRESHOLD = 10

# ============================================================================
# ENDPOINTS
# ============================================================================

@app.route('/health', methods=['GET'])
def health_check():
    """Health check endpoint"""
    try:
        redis_client.ping()
        return jsonify({
            'status': 'healthy',
            'service': 'ml-service',
            'redis': 'connected'
        }), 200
    except:
        return jsonify({
            'status': 'unhealthy',
            'redis': 'disconnected'
        }), 503


@app.route('/train-user', methods=['POST'])
def trigger_training():
    """
    Trigger user model training
    Called by PHP backend when user reaches threshold
    
    POST /train-user
    Body: {"user_id": 82}
    """
    data = request.get_json()
    user_id = data.get('user_id')
    
    if not user_id:
        return jsonify({'error': 'user_id required'}), 400
    
    # Queue training task (async)
    task = train_user_model.delay(user_id)
    
    return jsonify({
        'status': 'queued',
        'user_id': user_id,
        'task_id': task.id,
        'message': 'Training started in background'
    }), 202


@app.route('/user/<int:user_id>/model', methods=['GET'])
def get_user_model(user_id):
    """
    Get user's personalized model
    Returns cached model or triggers training if eligible
    
    GET /user/82/model
    """
    # Check cache
    cached_model = redis_client.get(f"user_model:{user_id}")
    
    if cached_model:
        model = json.loads(cached_model)
        return jsonify({
            'personalized': True,
            'cached': True,
            **model
        }), 200
    
    # Check if eligible for training
    # (In production, query database for meal count)
    # For now, return not ready
    return jsonify({
        'personalized': False,
        'message': 'Not enough meals for personalization',
        'required_meals': INITIAL_THRESHOLD
    }), 200


@app.route('/user/<int:user_id>/status', methods=['GET'])
def get_training_status(user_id):
    """
    Get training status for user
    
    GET /user/82/status
    """
    # Get last training metadata
    last_train = redis_client.get(f"last_train:{user_id}")
    
    if not last_train:
        return jsonify({
            'trained': False,
            'message': 'Never trained',
            'required_meals': INITIAL_THRESHOLD
        }), 200
    
    metadata = json.loads(last_train)
    
    return jsonify({
        'trained': True,
        'meal_count': metadata['meal_count'],
        'trained_at': metadata['trained_at'],
        'version': metadata.get('version', 1)
    }), 200


@app.route('/retrain-all', methods=['POST'])
def retrain_all_users():
    """
    Admin endpoint: Trigger retraining for all eligible users
    
    POST /retrain-all
    """
    from tasks import scheduled_retrain_all
    
    task = scheduled_retrain_all.delay()
    
    return jsonify({
        'status': 'queued',
        'task_id': task.id,
        'message': 'Batch retraining started'
    }), 202


if __name__ == '__main__':
    port = int(os.getenv('FLASK_PORT', 5000))
    app.run(host='0.0.0.0', port=port, debug=True)


# ============================================================================
# tasks.py - Celery Tasks (Async Training)
# ============================================================================

from celery import Celery
from celery.schedules import crontab
import redis
import json
import numpy as np
import psycopg2
from datetime import datetime
import os
from dotenv import load_dotenv

load_dotenv()

# Celery configuration
celery_app = Celery(
    'ml_tasks',
    broker=os.getenv('REDIS_URL', 'redis://localhost:6379/0'),
    backend=os.getenv('REDIS_URL', 'redis://localhost:6379/0')
)

celery_app.conf.update(
    task_serializer='json',
    result_serializer='json',
    accept_content=['json'],
    timezone='Asia/Jakarta',
    enable_utc=True,
)

# Redis client
redis_client = redis.Redis.from_url(
    os.getenv('REDIS_URL', 'redis://localhost:6379/0'),
    decode_responses=True
)

# Database connection
def get_db_connection():
    return psycopg2.connect(os.getenv('DATABASE_URL'))

# Load SVD model (once at startup)
print("Loading SVD model...")
with open('svd_model.json', 'r') as f:
    svd_model = json.load(f)

food_factors = np.array(svd_model['food_factors'])
food_to_idx = {int(k): int(v) for k, v in svd_model['food_to_idx'].items()}
n_foods = len(food_factors)

print(f"✓ SVD model loaded: {food_factors.shape}")

RETRAIN_THRESHOLD = 10


# ============================================================================
# TRAINING LOGIC
# ============================================================================

def get_user_meals(user_id):
    """Get user's meal history from database"""
    conn = get_db_connection()
    cur = conn.cursor()
    
    cur.execute("""
        SELECT food_id, COUNT(*) as count
        FROM food_records
        WHERE id_user = %s
        GROUP BY food_id
    """, (user_id,))
    
    meals = cur.fetchall()
    cur.close()
    conn.close()
    
    return meals


def get_user_demographics(user_id):
    """Get user demographics"""
    conn = get_db_connection()
    cur = conn.cursor()
    
    cur.execute("""
        SELECT gender, age, activity
        FROM users
        WHERE id_user = %s
    """, (user_id,))
    
    result = cur.fetchone()
    cur.close()
    conn.close()
    
    if result:
        return {
            'gender': result[0],
            'age': result[1],
            'activity': result[2]
        }
    return None


def generate_user_embedding(user_meals):
    """
    Generate user embedding from their meal history
    
    Args:
        user_meals: List of (food_id, count) tuples
    
    Returns:
        numpy array of shape (20,)
    """
    # Create user food vector
    user_vector = np.zeros(n_foods)
    
    for food_id, count in user_meals:
        if food_id in food_to_idx:
            food_idx = food_to_idx[food_id]
            user_vector[food_idx] = count
    
    # Project to embedding space: user_embedding = food_factors^T × user_vector
    user_embedding = food_factors.T @ user_vector
    
    # Normalize
    norm = np.linalg.norm(user_embedding)
    if norm > 0:
        user_embedding = user_embedding / norm
    
    return user_embedding


# ============================================================================
# CELERY TASKS
# ============================================================================

@celery_app.task(bind=True, max_retries=3)
def train_user_model(self, user_id):
    """
    Train personalized model for user
    
    Args:
        user_id: User ID to train model for
    
    Returns:
        dict: Training result
    """
    try:
        print(f"🔄 Training model for user {user_id}...")
        
        # Get user data
        user_meals = get_user_meals(user_id)
        meal_count = sum(count for _, count in user_meals)
        
        if meal_count < 10:
            print(f"⚠️  User {user_id} has only {meal_count} meals - skipping")
            return {
                'user_id': user_id,
                'success': False,
                'reason': 'insufficient_data',
                'meal_count': meal_count
            }
        
        # Get demographics
        demographics = get_user_demographics(user_id)
        
        if not demographics:
            print(f"⚠️  User {user_id} demographics not found")
            return {
                'user_id': user_id,
                'success': False,
                'reason': 'no_demographics'
            }
        
        # Generate embedding
        user_embedding = generate_user_embedding(user_meals)
        
        # Create mobile model
        mobile_model = {
            'user_id': user_id,
            'embedding': user_embedding.tolist(),
            'demographics': demographics,
            'version': 1,
            'trained_at': datetime.now().isoformat(),
            'n_meals': meal_count
        }
        
        # Cache model (30 days)
        redis_client.setex(
            f"user_model:{user_id}",
            30 * 24 * 3600,
            json.dumps(mobile_model)
        )
        
        # Store training metadata
        redis_client.set(
            f"last_train:{user_id}",
            json.dumps({
                'meal_count': meal_count,
                'trained_at': datetime.now().isoformat(),
                'version': 1
            })
        )
        
        print(f"✅ Model trained for user {user_id} ({meal_count} meals)")
        
        # TODO: Send push notification to user
        # send_push_notification(user_id, "Your recommendations just got better!")
        
        return {
            'user_id': user_id,
            'success': True,
            'meal_count': meal_count,
            'embedding_norm': float(np.linalg.norm(user_embedding))
        }
        
    except Exception as e:
        print(f"❌ Error training user {user_id}: {str(e)}")
        
        # Retry up to 3 times
        raise self.retry(exc=e, countdown=60)


@celery_app.task
def scheduled_retrain_all():
    """
    Scheduled task: Retrain all users with ≥10 new meals
    Runs weekly (Sunday 2 AM)
    """
    print("📅 Starting weekly scheduled retraining...")
    
    conn = get_db_connection()
    cur = conn.cursor()
    
    # Get all users with their meal counts
    cur.execute("""
        SELECT id_user, COUNT(*) as meal_count
        FROM food_records
        GROUP BY id_user
    """)
    
    users = cur.fetchall()
    cur.close()
    conn.close()
    
    eligible_users = []
    
    for user_id, current_count in users:
        # Get last training count
        last_train = redis_client.get(f"last_train:{user_id}")
        
        if not last_train:
            # Never trained
            if current_count >= 10:
                eligible_users.append(user_id)
        else:
            last_train_data = json.loads(last_train)
            new_meals = current_count - last_train_data['meal_count']
            
            if new_meals >= RETRAIN_THRESHOLD:
                eligible_users.append(user_id)
    
    print(f"Found {len(eligible_users)} users eligible for retraining")
    
    # Queue training tasks
    for user_id in eligible_users:
        train_user_model.delay(user_id)
    
    return {
        'scheduled_at': datetime.now().isoformat(),
        'total_users': len(users),
        'users_queued': len(eligible_users)
    }


# Celery Beat schedule
celery_app.conf.beat_schedule = {
    'weekly-retrain': {
        'task': 'tasks.scheduled_retrain_all',
        'schedule': crontab(hour=2, minute=0, day_of_week=0),  # Sunday 2 AM
    },
}


# ============================================================================
# UTILITIES
# ============================================================================

def send_push_notification(user_id, message):
    """
    Send push notification to user
    TODO: Implement with Firebase/OneSignal
    """
    print(f"📱 Push notification to user {user_id}: {message}")
    pass


if __name__ == '__main__':
    print("Celery worker ready. Run with:")
    print("celery -A tasks worker --loglevel=info")
