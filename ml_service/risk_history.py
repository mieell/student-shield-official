"""Deterministic trajectory scoring over durable database-backed risk history.

Combines Exponentially Weighted Moving Average (EWMA) control-limit monitoring
with recency-weighted linear trend estimation, grounded in temporal suicidal
ideation detection and intensive longitudinal mental health research:
- Sawhney, R., Joshi, H., Gandhi, S., & Shah, R. R. (2020). A Time-Aware Transformer
  Based Model for Suicide Ideation Detection on Social Media (STATENet). EMNLP 2020.
- De Choudhury, M., Kiciman, E., Dredze, M., Coppersmith, G., & Kumar, M. (2016).
  Discovering Shifts to Suicidal Ideation from Mental Health Content in Social Media.
  CHI '16 (DOI: 10.1145/2858036.2858207).
- Smit, A. C., Snippe, E., & Wichers, M. (2019). Psychotherapy and Psychosomatics, 88(4), 249-251.
- Kleiman, E. M., et al. (2017). Journal of Abnormal Psychology, 126(6), 726-738
  (DOI: 10.1037/abn0000273).
"""

from __future__ import annotations

from datetime import datetime, timedelta, timezone
from typing import Iterable, Mapping, Any


RISK_VALUES = {"LOW": 1.0, "MEDIUM": 2.0, "HIGH": 3.0, "CRITICAL": 4.0}
EWMA_ALPHA = 0.45
RECENCY_DECAY_BASE = 1.35


def _parse_timestamp(value: Any) -> datetime | None:
    if isinstance(value, datetime):
        parsed = value
    elif isinstance(value, str) and value.strip():
        try:
            parsed = datetime.fromisoformat(value.strip().replace("Z", "+00:00"))
        except ValueError:
            return None
    else:
        return None

    if parsed.tzinfo is None:
        return parsed.replace(tzinfo=timezone.utc)
    return parsed.astimezone(timezone.utc)


def _weighted_slope(values: list[float]) -> float:
    """Compute recency-weighted linear regression slope over ordered risk scores."""
    count = len(values)
    if count < 2:
        return 0.0

    weights = [RECENCY_DECAY_BASE ** index for index in range(count)]
    total_weight = sum(weights)
    if total_weight == 0:
        return 0.0

    mean_x = sum(w * index for index, w in enumerate(weights)) / total_weight
    mean_y = sum(w * value for w, value in zip(weights, values)) / total_weight
    denominator = sum(w * ((index - mean_x) ** 2) for index, w in enumerate(weights))
    if denominator == 0:
        return 0.0

    return sum(
        w * (index - mean_x) * (value - mean_y)
        for index, (w, value) in enumerate(zip(weights, values))
    ) / denominator


def _ewma_series(values: list[float], alpha: float = EWMA_ALPHA) -> list[float]:
    """Compute Exponentially Weighted Moving Average series over ordered scores."""
    if not values:
        return []
    series = [values[0]]
    for value in values[1:]:
        series.append(alpha * value + (1.0 - alpha) * series[-1])
    return series


def evaluate_risk_history(
    history: Iterable[Mapping[str, Any]],
    current_risk: str,
    *,
    now: datetime | None = None,
) -> tuple[bool, str, float]:
    """Return repeated-severe flag, trajectory, and escalation probability.

    The caller supplies prior risk records from the durable application database.
    The current preliminary classification is appended in memory, so no ML-service
    filesystem state is required and Space rebuilds cannot erase student history.
    """

    reference_time = now or datetime.now(timezone.utc)
    if reference_time.tzinfo is None:
        reference_time = reference_time.replace(tzinfo=timezone.utc)
    else:
        reference_time = reference_time.astimezone(timezone.utc)

    cutoff = reference_time - timedelta(days=14)
    records: list[tuple[datetime, str]] = []

    for item in history:
        risk = str(item.get("risk_level", "")).upper()
        timestamp = _parse_timestamp(item.get("created_at"))
        if risk in RISK_VALUES and timestamp is not None and cutoff <= timestamp <= reference_time:
            records.append((timestamp, risk))

    normalized_current = str(current_risk).upper()
    if normalized_current not in RISK_VALUES:
        normalized_current = "LOW"
    records.append((reference_time, normalized_current))
    records.sort(key=lambda record: record[0])

    recent_severe = sum(1 for _, risk in records if risk in {"HIGH", "CRITICAL"})
    if len(records) < 3:
        return recent_severe >= 3, "INSUFFICIENT_DATA", 0.0

    scores = [RISK_VALUES[risk] for _, risk in records]
    weighted_slope = _weighted_slope(scores)
    ewma = _ewma_series(scores)
    ewma_current = ewma[-1]
    ewma_delta = ewma[-1] - ewma[-2]

    recent_48h_cutoff = reference_time - timedelta(hours=48)
    recent_48h_severe = sum(
        1 for ts, risk in records if ts >= recent_48h_cutoff and risk in {"HIGH", "CRITICAL"}
    )
    recent_tail = [risk for _, risk in records[-4:]]
    has_recent_critical = "CRITICAL" in recent_tail

    # Upper EWMA control limit / acute clustering prevents repeated HIGH+CRITICAL
    # vents from flattening to STABLE under unweighted linear regression.
    acute_control_breach = normalized_current in {"HIGH", "CRITICAL"} and (
        (ewma_current >= 3.25 and has_recent_critical)
        or recent_48h_severe >= 3
    )
    upward_shift = (weighted_slope > 0.05) or (ewma_delta > 0.15 and normalized_current != "LOW")
    downward_shift = (weighted_slope < -0.05) or (ewma_delta < -0.15)

    if acute_control_breach or upward_shift:
        trajectory = "ESCALATING"
    elif downward_shift and not acute_control_breach:
        trajectory = "IMPROVING"
    else:
        trajectory = "STABLE"

    ewma_factor = max(0.0, (ewma_current - 1.0) / 3.0) * 0.45
    slope_factor = max(0.0, min(0.30, max(weighted_slope, ewma_delta) * 0.6))
    severity_factor = min(1.0, recent_severe / 3.0) * 0.25
    frequency_factor = min(1.0, len(records) / 10.0) * 0.10
    escalation_probability = round(
        min(0.99, 0.10 + ewma_factor + slope_factor + severity_factor + frequency_factor),
        2,
    )

    return recent_severe >= 3, trajectory, escalation_probability
