import sys
from pathlib import Path
from datetime import datetime, timedelta, timezone
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from risk_history import evaluate_risk_history


class RiskHistoryTest(unittest.TestCase):
    def setUp(self):
        self.now = datetime(2026, 9, 5, 12, 0, tzinfo=timezone.utc)

    def record(self, days_ago: int, risk_level: str) -> dict[str, str]:
        return {
            "risk_level": risk_level,
            "created_at": (self.now - timedelta(days=days_ago)).isoformat(),
        }

    def test_escalating_history_uses_database_records_and_current_risk(self):
        history = [self.record(2, "LOW"), self.record(1, "MEDIUM")]

        repeated, trajectory, probability = evaluate_risk_history(
            history, "HIGH", now=self.now
        )

        self.assertFalse(repeated)
        self.assertEqual("ESCALATING", trajectory)
        self.assertGreater(probability, 0.6)

    def test_three_recent_severe_records_trigger_repeated_severe_flag(self):
        history = [self.record(2, "HIGH"), self.record(1, "HIGH")]

        repeated, trajectory, probability = evaluate_risk_history(
            history, "CRITICAL", now=self.now
        )

        self.assertTrue(repeated)
        self.assertEqual("ESCALATING", trajectory)
        self.assertGreater(probability, 0.0)

    def test_records_older_than_fourteen_days_are_ignored(self):
        history = [
            self.record(30, "CRITICAL"),
            self.record(20, "CRITICAL"),
            self.record(1, "LOW"),
        ]

        repeated, trajectory, probability = evaluate_risk_history(
            history, "LOW", now=self.now
        )

        self.assertFalse(repeated)
        self.assertEqual("INSUFFICIENT_DATA", trajectory)
        self.assertEqual(0.0, probability)

    def test_invalid_records_are_ignored_without_changing_valid_history(self):
        history = [
            {"risk_level": "UNKNOWN", "created_at": self.now.isoformat()},
            {"risk_level": "HIGH", "created_at": "not-a-date"},
            self.record(2, "MEDIUM"),
            self.record(1, "MEDIUM"),
        ]

        repeated, trajectory, _ = evaluate_risk_history(
            history, "MEDIUM", now=self.now
        )

        self.assertFalse(repeated)
        self.assertEqual("STABLE", trajectory)

    def test_repeated_high_and_critical_vents_flag_escalating_instead_of_stable(self):
        history = [
            self.record(3, "HIGH"),
            self.record(2, "CRITICAL"),
            self.record(1, "HIGH"),
        ]

        repeated, trajectory, probability = evaluate_risk_history(
            history, "CRITICAL", now=self.now
        )

        self.assertTrue(repeated)
        self.assertEqual("ESCALATING", trajectory)
        self.assertGreaterEqual(probability, 0.75)

    def test_clustered_high_vents_within_48_hours_flag_escalating(self):
        history = [
            {
                "risk_level": "HIGH",
                "created_at": (self.now - timedelta(hours=12)).isoformat(),
            },
            {
                "risk_level": "HIGH",
                "created_at": (self.now - timedelta(hours=4)).isoformat(),
            },
        ]

        repeated, trajectory, probability = evaluate_risk_history(
            history, "HIGH", now=self.now
        )

        self.assertTrue(repeated)
        self.assertEqual("ESCALATING", trajectory)
        self.assertGreater(probability, 0.5)

    def test_deescalating_history_returns_improving(self):
        history = [
            self.record(4, "CRITICAL"),
            self.record(3, "CRITICAL"),
            self.record(2, "LOW"),
        ]

        repeated, trajectory, _ = evaluate_risk_history(
            history, "LOW", now=self.now
        )

        self.assertFalse(repeated)
        self.assertEqual("IMPROVING", trajectory)


if __name__ == "__main__":
    unittest.main()
