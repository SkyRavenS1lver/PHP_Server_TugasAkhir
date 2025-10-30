import numpy as np
import pandas as pd
import pickle
import json
import warnings
warnings.filterwarnings('ignore')

# ============================================
# LOAD BASELINE MODEL & DATA
# ============================================
def load_baseline_model():
    """Load trained KMeans model"""
    with open('kmeans_model.pkl', 'rb') as f:
        model_package = pickle.load(f)

    with open('food_recommendations_kmeans.json', 'r') as f:
        recommendations = json.load(f)

    return model_package, recommendations


def load_food_database(food_db_path='food_database.csv'):
    food_db = pd.read_csv(food_db_path)
    
    food_macros = {}
    for _, row in food_db.iterrows():
        # Calculate total energy from macros
        total_energy = (
            row['karbohidrat'] * 4 +
            row['protein'] * 4 +
            row['lemak'] * 9
        )
        
        if total_energy > 0:
            food_macros[int(row['id'])] = {
                'carb_pct': (row['karbohidrat'] * 4) / total_energy,
                'protein_pct': (row['protein'] * 4) / total_energy,
                'fat_pct': (row['lemak'] * 9) / total_energy,
            }
    
    return food_macros

def predict_user_cluster(user_data, scaler, kmeans):

    # Scale features using training scaler
    features_scaled = scaler.transform(user_data)

    # K-Means can predict directly (finds nearest cluster center)
    cluster = kmeans.predict(features_scaled)[0]

    return int(cluster)


# ============================================
# STAGE 2: WMA MODEL (Hybrid 30/70) - OPTIMIZED V5
# ============================================
class WMARecommender:
    def __init__(self, model_package, recommendations, food_macros,
                 decay_factor=0.9,
                 nutrition_weight=0.7,
                 deficit_threshold=0.03,
                 protein_reward_multiplier=350,
                 carb_reward_multiplier=175,
                 fat_reward_multiplier=210,
                 protein_penalty_multiplier=80,
                 carb_penalty_multiplier=110,
                 fat_penalty_multiplier=85):

        self.model_package = model_package
        self.recommendations = recommendations
        self.food_macros = food_macros
        self.decay_factor = decay_factor
        self.nutrition_weight = nutrition_weight

        # V3 Tunable thresholds and multipliers (Indonesian AKG: 65% carb, 10% protein, 25% fat)
        self.deficit_threshold = deficit_threshold
        self.protein_reward_multiplier = protein_reward_multiplier
        self.carb_reward_multiplier = carb_reward_multiplier
        self.fat_reward_multiplier = fat_reward_multiplier
        self.protein_penalty_multiplier = protein_penalty_multiplier
        self.carb_penalty_multiplier = carb_penalty_multiplier
        self.fat_penalty_multiplier = fat_penalty_multiplier

        # Model components
        self.scaler = model_package['scaler']
        self.kmeans = model_package['model']
        self.feature_cols = model_package['feature_cols']  # ['activity', 'bmi'] for clustering
        self.user_profiles = model_package['user_profiles']

    def extract_demographic_features(self, user_features):

        if isinstance(user_features, dict):
            return np.array([[
                user_features['activity'],
                user_features['bmi']
            ]])
        elif isinstance(user_features, pd.DataFrame):
            return user_features[['activity', 'bmi']].values
        else:
            # Assume it's already a numpy array with correct features
            return user_features

    def get_user_cluster(self, user_features):
       
        # Extract only demographic features (activity, bmi) for clustering
        demographic_features = self.extract_demographic_features(user_features)

        # Predict cluster using K-Means model (fast, accurate)
        cluster = predict_user_cluster(demographic_features, self.scaler, self.kmeans)

        return cluster
    
    def extract_user_macro_profile(self, user_features):
       
        # Try to extract macro percentages - adjust column names as needed
        try:
            if 'carb_pct' in user_features.columns:
                return {
                    'carb_pct': float(user_features['carb_pct'].iloc[0]),
                    'protein_pct': float(user_features['protein_pct'].iloc[0]),
                    'fat_pct': float(user_features['fat_pct'].iloc[0]),
                }
            # Alternative column names if different
            elif 'karbohidrat_pct' in user_features.columns:
                return {
                    'carb_pct': float(user_features['karbohidrat_pct'].iloc[0]),
                    'protein_pct': float(user_features['protein_pct'].iloc[0]),
                    'fat_pct': float(user_features['lemak_pct'].iloc[0]),
                }
            else:
                # If percentages not available, return None (will skip nutrition scoring)
                return None
        except:
            return None
    
    def calculate_nutrition_score(self, user_macro_profile, food_id):
        if user_macro_profile is None or food_id not in self.food_macros:
            return 0.0

        food_macro = self.food_macros[food_id]

        # Indonesian AKG targets (optimization goals)
        ideal_carb, ideal_protein, ideal_fat = 0.65, 0.10, 0.25

        # WHO 2003 acceptable ranges (safety boundaries)
        # Source: WHO Technical Report Series 916, 2003 - Diet, Nutrition and Prevention of Chronic Diseases
        WHO_CARB_MIN, WHO_CARB_MAX = 0.55, 0.75
        WHO_PROTEIN_MIN, WHO_PROTEIN_MAX = 0.10, 0.15
        WHO_FAT_MIN, WHO_FAT_MAX = 0.15, 0.30

        # Safe margins before triggering penalties (adjusted for WHO 2003 narrower protein range)
        # These define the "warning zone" before hitting WHO limits
        PROTEIN_SAFE_MARGIN = 0.035  # Penalty starts at 11.5% (15% - 3.5%)
        CARB_SAFE_MARGIN = 0.05      # Penalty starts at 70% (75% - 5%)
        FAT_SAFE_MARGIN = 0.05       # Penalty starts at 25% (30% - 5%)

        # User's current macros
        user_carb = user_macro_profile['carb_pct']
        user_protein = user_macro_profile['protein_pct']
        user_fat = user_macro_profile['fat_pct']

        # Calculate deficits (positive = needs more, negative = has excess)
        carb_deficit = ideal_carb - user_carb
        protein_deficit = ideal_protein - user_protein
        fat_deficit = ideal_fat - user_fat
        nutrition_score = 0.0

        # ============================================
        # PROTEIN SCORING (AKG: 10%, WHO 2003: 10-15%)
        # ============================================
        if food_macro['protein_pct'] > WHO_PROTEIN_MIN * 1.2:  # Score foods with >12% protein (1.2x target)
            # REWARD: User below target, encourage more protein (scaled down 36% for narrower WHO 2003 range)
            if protein_deficit > self.deficit_threshold:
                nutrition_score += protein_deficit * food_macro['protein_pct'] * self.protein_reward_multiplier
            # SAFE ZONE: 10-11.5% (no penalty, within WHO 2003 guidelines with margin)
            elif user_protein <= (WHO_PROTEIN_MAX - PROTEIN_SAFE_MARGIN):
                pass  # No intervention needed
            # WARNING ZONE: 11.5-15% (gentle penalty, approaching WHO 2003 ceiling)
            elif user_protein <= WHO_PROTEIN_MAX:
                excess = user_protein - (WHO_PROTEIN_MAX - PROTEIN_SAFE_MARGIN)
                nutrition_score -= excess * food_macro['protein_pct'] * self.protein_penalty_multiplier * 0.5  # 50% reduced penalty
            # DANGER ZONE: >15% (strong penalty, exceeds WHO 2003 ceiling)
            else:
                excess = user_protein - WHO_PROTEIN_MAX
                nutrition_score -= excess * food_macro['protein_pct'] * self.protein_penalty_multiplier
        # ============================================
        # CARBS SCORING (AKG: 65%, WHO 2003: 55-75%)
        # ============================================
        if food_macro['carb_pct'] > WHO_CARB_MIN:  # Score foods with >55% carbs (WHO 2003 minimum)
            # REWARD: User below target, encourage more carbs
            if carb_deficit > self.deficit_threshold:
                nutrition_score += carb_deficit * food_macro['carb_pct'] * self.carb_reward_multiplier
            # SAFE ZONE: 55-70% (no penalty, within WHO 2003 guidelines with margin)
            elif user_carb <= (WHO_CARB_MAX - CARB_SAFE_MARGIN):
                pass  # No intervention needed
            # WARNING ZONE: 70-75% (gentle penalty, approaching WHO 2003 ceiling)
            elif user_carb <= WHO_CARB_MAX:
                excess = user_carb - (WHO_CARB_MAX - CARB_SAFE_MARGIN)
                nutrition_score -= excess * food_macro['carb_pct'] * self.carb_penalty_multiplier * 0.5  # 50% reduced penalty
            # DANGER ZONE: >75% (strong penalty, exceeds WHO 2003 ceiling)
            else:
                excess = user_carb - WHO_CARB_MAX
                nutrition_score -= excess * food_macro['carb_pct'] * self.carb_penalty_multiplier
        # ============================================
        # FAT SCORING (AKG: 25%, WHO 2003: 15-30%)
        # ============================================
        if food_macro['fat_pct'] > WHO_FAT_MIN:  # Score foods with >15% fat (WHO 2003 minimum)
            # REWARD: User below target, encourage more fat
            if fat_deficit > self.deficit_threshold:
                nutrition_score += fat_deficit * food_macro['fat_pct'] * self.fat_reward_multiplier
            # SAFE ZONE: 15-25% (no penalty, within WHO 2003 guidelines with margin)
            elif user_fat <= (WHO_FAT_MAX - FAT_SAFE_MARGIN):
                pass  # No intervention needed
            # WARNING ZONE: 25-30% (gentle penalty, approaching WHO 2003 ceiling)
            elif user_fat <= WHO_FAT_MAX:
                excess = user_fat - (WHO_FAT_MAX - FAT_SAFE_MARGIN)
                nutrition_score -= excess * food_macro['fat_pct'] * self.fat_penalty_multiplier * 0.5  # 50% reduced penalty
            # DANGER ZONE: >30% (strong penalty, exceeds WHO 2003 ceiling)
            else:
                excess = user_fat - WHO_FAT_MAX
                nutrition_score -= excess * food_macro['fat_pct'] * self.fat_penalty_multiplier
        return nutrition_score
    
    def calculate_wma_scores(self, user_foods, user_macro_profile, cluster_id):
        cluster_foods = self.recommendations[f'cluster_{cluster_id}']
        base_scores = {f['food_id']: f['recommendation_score'] for f in cluster_foods}

        # V5 CRITICAL: Add user-eaten foods not in cluster with neutral base score (0.0)
        # This allows user preferences outside their demographic cluster to be recommended
        # Requires ~3 occurrences (out of 30 records) to rank in top 30
        user_food_ids = set(record['food_id'] for record in user_foods)
        for food_id in user_food_ids:
            if food_id not in base_scores:
                base_scores[food_id] = 0.0  # Neutral starting point

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

        # Calculate hybrid scores
        hybrid_scores = {}
        for food_id in base_scores.keys():
            cluster_score = base_scores[food_id]

            # Frequency component (user preference signal)
            user_freq = food_frequency.get(food_id, 0)
            user_recency = food_recency_weight.get(food_id, 0)
            freq_boost = user_freq * user_recency * 10

            # Nutrition component (health guidance signal) - V5 tuned scoring
            nutrition_boost = self.calculate_nutrition_score(user_macro_profile, food_id)

            hybrid_scores[food_id] = (
                cluster_score +  # Base cluster popularity
                freq_boost * (1 - self.nutrition_weight) +  # 30% user preference (default 0.7 weight)
                nutrition_boost * self.nutrition_weight  # 70% nutrition guidance (validated optimal)
            )

        # Sort and return
        sorted_foods = sorted(hybrid_scores.items(), key=lambda x: x[1], reverse=True)
        return sorted_foods
    
    def get_recommendations(self, user_features, user_foods, cluster_id, top_n=30):
        # Extract user's macro profile from user_features
        user_macro_profile = self.extract_user_macro_profile(user_features)
        
        # Get hybrid scores
        hybrid_scores = self.calculate_wma_scores(user_foods, user_macro_profile, cluster_id)
        
        result = []
        for food_id, score in hybrid_scores[:top_n]:
            result.append({
                'food_id': int(food_id),
                'wma_score': float(score)
            })
        
        return result