import numpy as np
import pickle
import json
import warnings
warnings.filterwarnings('ignore')


# ============================================
# LOAD BASELINE MODEL & DATA
# ============================================
def load_baseline_model():
    """Load trained Hierarchical model"""
    with open('hierarchical_model.pkl', 'rb') as f:
        model_package = pickle.load(f)
    
    with open('food_recommendations.json', 'r') as f:
        recommendations = json.load(f)
    
    return model_package, recommendations


# ============================================
# STAGE 2: WMA MODEL
# ============================================
class WMARecommender:
    """WMA-based recommendations within cluster"""
    
    def __init__(self, model_package, recommendations, decay_factor=0.9):
        self.model_package = model_package
        self.recommendations = recommendations
        self.decay_factor = decay_factor
        self.scaler = model_package['scaler']
        self.feature_cols = model_package['feature_cols']  # Use the saved feature_cols
        self.user_profiles = model_package['user_profiles']
    
    def get_user_cluster(self, user_features):
        """Get cluster for user based on demographics"""
        # Get training data with correct feature columns
        X_scaled_train = self.scaler.transform(
            self.user_profiles[self.feature_cols]
        )
        
        # Scale the input features
        features_scaled = self.scaler.transform(user_features)
        
        # Find nearest neighbor
        distances = np.linalg.norm(X_scaled_train - features_scaled, axis=1)
        nearest_idx = np.argmin(distances)
        cluster = int(self.user_profiles['cluster'].values[nearest_idx])
        
        return cluster
    
    def calculate_wma_scores(self, user_foods, cluster_id):
        """
        Calculate WMA-weighted recommendation scores
        user_foods: list of dicts [{'food_id': int, 'date': str}, ...]
        """
        cluster_foods = self.recommendations[f'cluster_{cluster_id}']
        base_scores = {f['food_id']: f['recommendation_score'] for f in cluster_foods}
        
        # Count frequency and calculate recency weight
        food_frequency = {}
        food_recency_weight = {}
        
        for idx, record in enumerate(user_foods):
            food_id = record['food_id']
            
            # Frequency count
            food_frequency[food_id] = food_frequency.get(food_id, 0) + 1
            
            # Recency weight (most recent = 1.0, older = decay)
            recency_idx = len(user_foods) - idx - 1
            weight = self.decay_factor ** (len(user_foods) - recency_idx - 1)
            food_recency_weight[food_id] = food_recency_weight.get(food_id, 0) + weight
        
        # Calculate WMA scores
        wma_scores = {}
        for food_id in base_scores.keys():
            cluster_score = base_scores[food_id]
            user_freq = food_frequency.get(food_id, 0)
            user_recency = food_recency_weight.get(food_id, 0)
            
            # WMA = base cluster score + (user frequency × recency weight)
            wma_scores[food_id] = cluster_score + (user_freq * user_recency * 10)
        
        # Sort and return
        sorted_foods = sorted(wma_scores.items(), key=lambda x: x[1], reverse=True)
        return sorted_foods
    
    def get_recommendations(self, user_features, user_foods, cluster_id, top_n=30):
        """Get WMA-weighted recommendations"""
        wma_scores = self.calculate_wma_scores(user_foods, cluster_id)
        
        result = []
        for food_id, score in wma_scores[:top_n]:
            result.append({
                'food_id': int(food_id),
                'wma_score': float(score)
            })
        
        return result