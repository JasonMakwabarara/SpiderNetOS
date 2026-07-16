"""
SpiderNet OS - DeepSeek Calibration Analyzer
Uses DeepSeek v4 to analyze sim-to-real gaps and suggest calibration improvements
"""

import json
import urllib.request
from typing import Dict, List, Optional, Any
from dataclasses import dataclass
from datetime import datetime
import numpy as np


@dataclass
class CalibrationInsight:
    """AI-generated insight about sim-to-real gap"""
    timestamp: str
    metric_name: str
    sim_value: float
    real_value: float
    gap_percentage: float
    root_cause: str
    suggested_fix: str
    confidence: float


class DeepSeekCalibrationAnalyzer:
    """
    Analyzes sim-to-real discrepancies using DeepSeek reasoning.
    
    Capabilities:
    - Identify why simulation diverges from reality
    - Suggest calibration parameter adjustments
    - Detect systematic biases in specific market conditions
    - Explain calibration quality to operators
    """
    
    def __init__(
        self,
        ollama_url: str = "http://localhost:11434",
        model: str = "deepseek-v4-flash:cloud",
        timeout: int = 60
    ):
        self.ollama_url = ollama_url
        self.model = model
        self.timeout = timeout
        self.insight_history: List[CalibrationInsight] = []
        
    def _call_deepseek(
        self,
        prompt: str,
        temperature: float = 0.3,
        max_tokens: int = 2000
    ) -> str:
        """Call DeepSeek via Ollama"""
        
        data = json.dumps({
            "model": self.model,
            "prompt": prompt,
            "stream": False,
            "options": {
                "temperature": temperature,
                "num_predict": max_tokens
            }
        }).encode()
        
        req = urllib.request.Request(
            f"{self.ollama_url}/api/generate",
            data=data,
            headers={"Content-Type": "application/json"},
            method="POST"
        )
        
        try:
            with urllib.request.urlopen(req, timeout=self.timeout) as resp:
                result = json.loads(resp.read().decode())
                return result.get("response", "")
        except Exception as e:
            print(f"[DeepSeekCalibration] Error: {e}")
            return ""
    
    def analyze_gap(
        self,
        metric_name: str,
        sim_values: List[float],
        real_values: List[float],
        market_context: Dict[str, Any]
    ) -> CalibrationInsight:
        """
        Analyze discrepancy between simulation and reality.
        
        Args:
            metric_name: e.g., 'cpm_meta', 'conversion_rate', 'roas'
            sim_values: Recent simulated values
            real_values: Corresponding real-world values
            market_context: Current market conditions
        """
        
        if len(sim_values) == 0 or len(real_values) == 0:
            return CalibrationInsight(
                timestamp=datetime.utcnow().isoformat(),
                metric_name=metric_name,
                sim_value=0.0,
                real_value=0.0,
                gap_percentage=0.0,
                root_cause="Insufficient data",
                suggested_fix="Collect more calibration data",
                confidence=0.0
            )
        
        # Calculate statistics
        sim_avg = np.mean(sim_values)
        real_avg = np.mean(real_values)
        gap_pct = ((real_avg - sim_avg) / sim_avg * 100) if sim_avg != 0 else 0
        
        prompt = f"""Analyze this sim-to-real calibration gap:

METRIC: {metric_name}

SIMULATION VALUES (last {len(sim_values)} data points):
- Average: {sim_avg:.4f}
- Values: {sim_values[-10:]}  # Last 10

REAL-WORLD VALUES (last {len(real_values)} data points):
- Average: {real_avg:.4f}
- Values: {real_values[-10:]}  # Last 10

GAP: {gap_pct:.1f}% (real is {'higher' if gap_pct > 0 else 'lower'} than simulation)

MARKET CONTEXT:
- Current competition level: {market_context.get('competition_index', 'unknown')}
- Market trend: {market_context.get('market_trend', 'unknown')}
- Day of week: {market_context.get('day_of_week', 'unknown')}
- Active campaigns: {market_context.get('active_campaigns', 'unknown')}

ANALYZE:
1. What could cause this gap? (model limitations, market changes, data quality)
2. Is this a systematic bias or random noise?
3. What calibration adjustment would fix this?
4. How confident are you in this diagnosis?

Respond with JSON:
{{
    "root_cause": "Explanation of why the gap exists",
    "is_systematic": true/false,
    "suggested_fix": "Specific calibration adjustment (e.g., 'Multiply CPM by 1.15')",
    "confidence": 0.0-1.0,
    "urgency": "low|medium|high"
}}"""
        
        response = self._call_deepseek(prompt, temperature=0.2)
        
        try:
            # Extract JSON
            if "```json" in response:
                json_str = response.split("```json")[1].split("```")[0]
            elif "```" in response:
                json_str = response.split("```")[1].split("```")[0]
            else:
                json_str = response
            
            data = json.loads(json_str.strip())
            
            insight = CalibrationInsight(
                timestamp=datetime.utcnow().isoformat(),
                metric_name=metric_name,
                sim_value=sim_avg,
                real_value=real_avg,
                gap_percentage=gap_pct,
                root_cause=data.get("root_cause", "Unknown"),
                suggested_fix=data.get("suggested_fix", "No suggestion"),
                confidence=data.get("confidence", 0.5)
            )
            
            self.insight_history.append(insight)
            return insight
            
        except (json.JSONDecodeError, KeyError) as e:
            print(f"[DeepSeekCalibration] Parse error: {e}")
            return CalibrationInsight(
                timestamp=datetime.utcnow().isoformat(),
                metric_name=metric_name,
                sim_value=sim_avg,
                real_value=real_avg,
                gap_percentage=gap_pct,
                root_cause="Could not parse DeepSeek response",
                suggested_fix="Manual review required",
                confidence=0.0
            )
    
    def generate_calibration_report(
        self,
        all_gaps: List[CalibrationInsight],
        sim_to_real_bridge_stats: Dict
    ) -> str:
        """
        Generate human-readable calibration report using DeepSeek.
        
        Provides actionable recommendations for operators.
        """
        
        # Summarize gaps
        critical_gaps = [g for g in all_gaps if abs(g.gap_percentage) > 20]
        warning_gaps = [g for g in all_gaps if 10 < abs(g.gap_percentage) <= 20]
        
        prompt = f"""Generate a sim-to-real calibration report for operators.

CALIBRATION STATUS:
- Total metrics tracked: {len(all_gaps)}
- Critical gaps (>20%): {len(critical_gaps)}
- Warning gaps (10-20%): {len(warning_gaps)}
- Acceptable (<10%): {len(all_gaps) - len(critical_gaps) - len(warning_gaps)}

CRITICAL GAPS:
{chr(10).join([f"- {g.metric_name}: {g.gap_percentage:.1f}% - {g.root_cause[:100]}" for g in critical_gaps[:5]])}

BRIDGE STATISTICS:
- Data points collected: {sim_to_real_bridge_stats.get('data_points', 0)}
- Calibration quality: {sim_to_real_bridge_stats.get('quality', 'unknown')}
- Last calibration: {sim_to_real_bridge_stats.get('last_calibration', 'unknown')}

WRITE A REPORT WITH:
1. Executive summary (1-2 sentences)
2. Critical issues requiring immediate attention
3. Recommended actions in priority order
4. Expected impact of fixing these gaps

Use professional but accessible language. Format with clear sections."""
        
        return self._call_deepseek(prompt, temperature=0.4, max_tokens=3000)
    
    def predict_calibration_drift(
        self,
        historical_gaps: List[List[float]],  # Gap % over time for multiple metrics
        time_horizon_hours: int = 168  # 1 week
    ) -> Dict[str, Any]:
        """
        Predict future calibration drift using pattern analysis.
        
        Helps proactively adjust simulation before gaps become critical.
        """
        
        if not historical_gaps or len(historical_gaps[0]) < 24:
            return {
                "predicted_drift": "insufficient_data",
                "confidence": 0.0,
                "recommendation": "Collect at least 24 hours of data before prediction"
            }
        
        # Calculate trends
        trends = []
        for gaps in historical_gaps:
            if len(gaps) >= 2:
                trend = (gaps[-1] - gaps[0]) / len(gaps)
                trends.append(trend)
        
        avg_trend = np.mean(trends) if trends else 0
        
        prompt = f"""Predict future calibration drift based on historical patterns.

HISTORICAL GAP TRENDS (per hour):
- Average drift rate: {avg_trend:.3f}% per hour
- Trend direction: {'increasing' if avg_trend > 0 else 'decreasing' if avg_trend < 0 else 'stable'}
- Data span: {len(historical_gaps[0]) if historical_gaps else 0} hours

PREDICTION HORIZON: {time_horizon_hours} hours (1 week)

Based on this trend and typical market simulation patterns:
1. What will the calibration gap be in {time_horizon_hours} hours if no adjustments are made?
2. What factors could accelerate or decelerate this drift?
3. When should the next calibration be scheduled?

Respond with JSON:
{{
    "predicted_gap_percentage": 25.0,
    "confidence": 0.7,
    "factors": ["factor 1", "factor 2"],
    "recommended_calibration_time": "48 hours from now",
    "urgency": "medium"
}}"""
        
        response = self._call_deepseek(prompt, temperature=0.3)
        
        try:
            if "```json" in response:
                json_str = response.split("```json")[1].split("```")[0]
            elif "```" in response:
                json_str = response.split("```")[1].split("```")[0]
            else:
                json_str = response
            
            return json.loads(json_str.strip())
        except:
            return {
                "predicted_gap_percentage": abs(avg_trend * time_horizon_hours),
                "confidence": 0.3,
                "factors": ["trend continuation"],
                "recommended_calibration_time": f"{min(72, int(time_horizon_hours/2))} hours",
                "urgency": "medium" if abs(avg_trend) > 0.1 else "low"
            }
    
    def explain_sim_to_real_bridge(
        self,
        target_audience: str = "operator"  # 'operator', 'engineer', 'executive'
    ) -> str:
        """
        Generate explanation of how sim-to-real bridge works.
        
        Tailored to different audiences.
        """
        
        audience_prompts = {
            "operator": "Explain to someone managing campaigns day-to-day. Focus on what they should watch for and when to alert engineers.",
            "engineer": "Explain to a software engineer. Include technical details about calibration algorithms, residual learning, and domain randomization.",
            "executive": "Explain to a business executive. Focus on risk management, ROI impact, and business value of accurate simulation."
        }
        
        prompt = f"""Explain the SpiderNetOS sim-to-real bridge concept.

{audience_prompts.get(target_audience, audience_prompts['operator'])}

KEY CONCEPTS TO COVER:
- Why simulation needs calibration
- How real data improves the simulation
- What happens when simulation diverges from reality
- The role of domain randomization

Keep it concise (200-300 words) but informative."""
        
        return self._call_deepseek(prompt, temperature=0.5, max_tokens=1500)
