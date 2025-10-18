import redis
import json
from typing import Optional, Dict, Any
import os

class RedisManager:
    """Redis cache manager for ML models"""
    
    def __init__(self):
        self.client = redis.Redis.from_url(
            os.getenv('REDIS_URL', 'redis://localhost:6379/0'),
            decode_responses=False
        )
        self.client.ping()  # Test connection
    
    def save_user_model(self, user_id: int, model: Dict, ttl_days: int = 30):
        """Cache user model"""
        key = f"user_model:{user_id}"
        self.client.setex(key, ttl_days * 86400, json.dumps(model))
    
    def get_user_model(self, user_id: int) -> Optional[Dict]:
        """Get cached user model"""
        key = f"user_model:{user_id}"
        data = self.client.get(key)
        return json.loads(data) if data else None
    
    def save_training_metadata(self, user_id: int, metadata: Dict):
        """Save training metadata (no expiry)"""
        key = f"last_train:{user_id}"
        self.client.set(key, json.dumps(metadata))
    
    def get_training_metadata(self, user_id: int) -> Optional[Dict]:
        """Get training metadata"""
        key = f"last_train:{user_id}"
        data = self.client.get(key)
        return json.loads(data) if data else None
    
    def acquire_lock(self, user_id: int, timeout: int = 300) -> bool:
        """Acquire training lock (prevent duplicate training)"""
        key = f"training_lock:{user_id}"
        return self.client.set(key, "1", nx=True, ex=timeout)
    
    def release_lock(self, user_id: int):
        """Release training lock"""
        key = f"training_lock:{user_id}"
        self.client.delete(key)

    def save_result(self, result_key, user_id: int, recommendations: Any):
        self.client.setex(result_key, 3600, json.dumps({
            'user_id': user_id,
            'recommendations': recommendations
        }))