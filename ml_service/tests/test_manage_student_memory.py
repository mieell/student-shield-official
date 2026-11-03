import os
import json
import numpy as np
import pytest
import faiss
import ml_service.main as main


@pytest.fixture
def temp_memory_dir(tmp_path, monkeypatch):
    """Redirect MEMORY_DIR to a temporary directory for test isolation."""
    mem_dir = str(tmp_path / "faiss_memory")
    os.makedirs(mem_dir, exist_ok=True)
    monkeypatch.setattr(main, "MEMORY_DIR", mem_dir)
    return mem_dir


def test_manage_student_memory_empty_student_id(temp_memory_dir):
    """Test empty or None student_id guard clause."""
    embedding = np.zeros((1, 768), dtype=np.float32)
    b_escalate, trajectory, escalation_prob = main.manage_student_memory(
        "", "test vent text", "LOW", embedding
    )
    assert b_escalate is False
    assert trajectory == "INSUFFICIENT_DATA"
    assert escalation_prob == 0.0

    b_escalate_none, trajectory_none, escalation_prob_none = main.manage_student_memory(
        None, "test vent text", "LOW", embedding
    )
    assert b_escalate_none is False
    assert trajectory_none == "INSUFFICIENT_DATA"
    assert escalation_prob_none == 0.0


def test_manage_student_memory_initialization(temp_memory_dir):
    """Test memory initialization for a new student (creating .index and _meta.json)."""
    student_id = "student_101"
    index_path = os.path.join(temp_memory_dir, f"{student_id}.index")
    meta_path = os.path.join(temp_memory_dir, f"{student_id}_meta.json")

    assert not os.path.exists(index_path)
    assert not os.path.exists(meta_path)

    embedding = np.random.randn(1, 768).astype(np.float32)
    b_escalate, trajectory, escalation_prob = main.manage_student_memory(
        student_id, "I feel sad today", "MEDIUM", embedding
    )

    assert b_escalate is False
    assert trajectory == "INSUFFICIENT_DATA"
    assert escalation_prob == 0.0

    # Verify disk artifacts
    assert os.path.exists(index_path)
    assert os.path.exists(meta_path)

    # Check FAISS index
    index = faiss.read_index(index_path)
    assert index.ntotal == 1

    # Check JSON metadata
    with open(meta_path, "r") as f:
        meta = json.load(f)
    assert len(meta) == 1
    assert meta[0]["risk_level"] == "MEDIUM"
    assert "timestamp" in meta[0]


def test_manage_student_memory_persistent_update(temp_memory_dir):
    """Test subsequent memory updates append to existing index and metadata."""
    student_id = "student_102"
    index_path = os.path.join(temp_memory_dir, f"{student_id}.index")
    meta_path = os.path.join(temp_memory_dir, f"{student_id}_meta.json")

    embedding1 = np.random.randn(1, 768).astype(np.float32)
    main.manage_student_memory(student_id, "First vent", "LOW", embedding1)

    embedding2 = np.random.randn(1, 768).astype(np.float32)
    main.manage_student_memory(student_id, "Second vent", "MEDIUM", embedding2)

    index = faiss.read_index(index_path)
    assert index.ntotal == 2

    with open(meta_path, "r") as f:
        meta = json.load(f)
    assert len(meta) == 2
    assert meta[0]["risk_level"] == "LOW"
    assert meta[1]["risk_level"] == "MEDIUM"


def test_manage_student_memory_trajectories(temp_memory_dir):
    """Test trajectory calculations (ESCALATING, IMPROVING, STABLE)."""
    embedding = np.zeros((1, 768), dtype=np.float32)

    # Escalating: LOW -> MEDIUM -> HIGH
    student_escalating = "student_escalating"
    main.manage_student_memory(student_escalating, "v1", "LOW", embedding)
    main.manage_student_memory(student_escalating, "v2", "MEDIUM", embedding)
    b_escalate, traj, prob = main.manage_student_memory(student_escalating, "v3", "HIGH", embedding)
    assert traj == "ESCALATING"
    assert prob > 0.0

    # Improving: HIGH -> MEDIUM -> LOW
    student_improving = "student_improving"
    main.manage_student_memory(student_improving, "v1", "HIGH", embedding)
    main.manage_student_memory(student_improving, "v2", "MEDIUM", embedding)
    b_escalate, traj, prob = main.manage_student_memory(student_improving, "v3", "LOW", embedding)
    assert traj == "IMPROVING"

    # Stable: MEDIUM -> MEDIUM -> MEDIUM
    student_stable = "student_stable"
    main.manage_student_memory(student_stable, "v1", "MEDIUM", embedding)
    main.manage_student_memory(student_stable, "v2", "MEDIUM", embedding)
    b_escalate, traj, prob = main.manage_student_memory(student_stable, "v3", "MEDIUM", embedding)
    assert traj == "STABLE"


def test_manage_student_memory_severe_threshold(temp_memory_dir):
    """Test that 3 or more recent HIGH/CRITICAL vents triggers recent_severe escalation flag."""
    student_id = "student_severe"
    embedding = np.zeros((1, 768), dtype=np.float32)

    main.manage_student_memory(student_id, "v1", "HIGH", embedding)
    main.manage_student_memory(student_id, "v2", "CRITICAL", embedding)
    b_escalate1, _, _ = main.manage_student_memory(student_id, "v3", "LOW", embedding)
    assert b_escalate1 is False  # Only 2 severe

    b_escalate2, traj, prob = main.manage_student_memory(student_id, "v4", "HIGH", embedding)
    assert b_escalate2 is True   # Now 3 severe
    assert prob > 0.5


def test_manage_student_memory_error_handling(temp_memory_dir):
    """Test error handling when FAISS index file is corrupted."""
    student_id = "student_corrupt"
    index_path = os.path.join(temp_memory_dir, f"{student_id}.index")
    meta_path = os.path.join(temp_memory_dir, f"{student_id}_meta.json")

    # Write corrupt data
    with open(index_path, "w") as f:
        f.write("corrupted data")
    with open(meta_path, "w") as f:
        f.write("[]")

    embedding = np.zeros((1, 768), dtype=np.float32)
    b_escalate, trajectory, escalation_prob = main.manage_student_memory(
        student_id, "test text", "HIGH", embedding
    )
    assert b_escalate is False
    assert trajectory == "INSUFFICIENT_DATA"
    assert escalation_prob == 0.0
