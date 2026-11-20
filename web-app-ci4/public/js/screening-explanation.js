function cleanScreeningText(raw, finalRisk = '') {
    if (typeof raw !== 'string') return '';
    const riskUpper = String(finalRisk || '').toUpperCase();
    const hadCriticalHistory = /CRITICAL\s+ESCALATION:|Repeated\s+HIGH\s+distress\s+with\s+escalating\s+trajectory\.?/i.test(raw);
    const hadHighHistory = /HIGH\s+ESCALATION:|Persistent\s+MEDIUM\s+distress\s+with\s+repeated\s+high-risk\s+history\.?|Repeated\s+worsening\s+emotional\s+distress\s+over\s+14\s+days\.?/i.test(raw);

    let cleaned = raw
        // Strip internal bracket tags and intermediate ML boilerplate so counselors see one consistent explanation.
        .replace(/\[Predictive\s+Recurrence\s+Risk:\s*\d+%\]\s*/gi, '')
        .replace(/\[(?:Groq\s+)?Clinical\s+Escalation(?::\s*[^\]]+|\s*\([^)]*\))?\]\s*/gi, '')
        .replace(/\[(?:Groq\s+)?Context(?:ual)?\s+Clarification(?::\s*[^\]]+|\s*\([^)]*\))?\]\s*/gi, '')
        .replace(/\[(?:Groq\s+Clinical\s+Context|Clinical\s+Review|(?:Groq|Gemini)\s+Fallback)\]\s*/gi, '')
        .replace(/\b(?:CRITICAL|HIGH)\s+ESCALATION:\s*/gi, '')
        .replace(/Repeated\s+HIGH\s+distress\s+with\s+escalating\s+trajectory\.?\s*/gi, '')
        .replace(/Persistent\s+MEDIUM\s+distress\s+with\s+repeated\s+high-risk\s+history\.?\s*/gi, '')
        .replace(/Repeated\s+worsening\s+emotional\s+distress\s+over\s+14\s+days\.?\s*/gi, '')
        .replace(/\b(?:CRITICAL|HIGH|MEDIUM|LOW):\s*Strong negative sentiment\/distress from ML neural networks\.?\s*/gi, '')
        .replace(/\b(?:CRITICAL|HIGH|MEDIUM|LOW):\s*(?=Academic pressure|Emotional distress|Passive|Explicit)/gi, '')
        .replace(/\bHigh emotional intensity\.?\s*/gi, '')
        .replace(/\bModerate negative sentiment\.?\s*/gi, '')
        .replace(/\bAtypical sentiment pattern flagged by heuristics\.?\s*/gi, '')
        .replace(/\bNLP:\s*/gi, '')
        .replace(/\b(?:Deterministic safety floor retained|Safety safeguards retained the higher review priority)\.?\s*/gi, '')
        .replace(/\b(?:Groq|Gemini)(?:\s+AI)?\b/gi, 'automated context screening')
        .replace(/\s{2,}/g, ' ')
        .trim();

    if (hadCriticalHistory && riskUpper === 'CRITICAL' && !/^Elevated to CRITICAL/i.test(cleaned)) {
        cleaned = ('Elevated to CRITICAL due to repeated high-risk check-ins over the past 14 days. ' + cleaned).trim();
    } else if (hadHighHistory && riskUpper === 'HIGH' && !/^Elevated to HIGH/i.test(cleaned)) {
        cleaned = ('Elevated to HIGH due to worsening distress across recent check-ins over the past 14 days. ' + cleaned).trim();
    }

    if (!cleaned) return '';
    return cleaned.charAt(0).toUpperCase() + cleaned.slice(1);
}

function showScreeningExplanation(vent) {
    let panel = document.getElementById('screeningExplanation');
    if (!panel) {
        panel = document.createElement('section');
        panel.id = 'screeningExplanation';
        panel.className = 'mt-5 border border-[#cfe0d5] rounded-xl p-4 bg-[#f4f8f5]';
        document.getElementById('modalContent').after(panel);
    }
    panel.replaceChildren();

    const title = document.createElement('h4');
    title.className = 'font-semibold text-[#164a36] mb-3';
    title.textContent = 'Screening analysis';
    panel.append(title);

    const riskLevel = (vent.risk_level || 'UNAVAILABLE').toUpperCase();
    const numericScore = Number(vent.risk_percent);
    const formattedScore = Number.isFinite(numericScore) ? `${Math.round(numericScore)}%` : '—';
    const badgeStyles = {
        CRITICAL: 'bg-red-100 text-red-800 border-red-200',
        HIGH: 'bg-orange-100 text-orange-800 border-orange-200',
        MEDIUM: 'bg-amber-100 text-amber-900 border-amber-200',
        LOW: 'bg-emerald-100 text-emerald-800 border-emerald-200',
    };

    const priorityBlock = document.createElement('div');
    priorityBlock.className = 'mb-3';
    const priorityTerm = document.createElement('p');
    priorityTerm.className = 'text-xs font-semibold text-[#435e51] mb-1.5';
    priorityTerm.textContent = 'Review priority';
    const priorityRow = document.createElement('div');
    priorityRow.className = 'flex items-center flex-wrap gap-2';
    const priorityBadge = document.createElement('span');
    priorityBadge.className = `inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold border ${badgeStyles[riskLevel] || 'bg-gray-100 text-gray-800 border-gray-200'}`;
    priorityBadge.textContent = riskLevel;
    const priorityScore = document.createElement('span');
    priorityScore.className = 'text-sm font-medium text-[#203d30]';
    priorityScore.textContent = `Severity score: ${formattedScore}`;
    priorityRow.append(priorityBadge, priorityScore);
    priorityBlock.append(priorityTerm, priorityRow);
    panel.append(priorityBlock);

    const sources = {
        model: 'Automated ML + Context Screening',
        groq: 'Automated Context Screening',
        gemini: 'Automated Context Screening',
        local: 'Keyword Safety Screening',
        unknown: 'Source unavailable for this entry',
    };

    const cleanedReasoning = cleanScreeningText(vent.screening_analysis?.reasoning, riskLevel);
    const explanationBlock = document.createElement('div');
    explanationBlock.className = 'mb-3';
    const explanationTerm = document.createElement('p');
    explanationTerm.className = 'text-xs font-semibold text-[#435e51] mb-1.5';
    explanationTerm.textContent = 'Why this priority was suggested';
    const explanationBox = document.createElement('p');
    explanationBox.className = 'text-sm text-[#1b3629] leading-relaxed whitespace-pre-line bg-white border border-[#dce8e0] rounded-lg px-3.5 py-2.5';
    explanationBox.textContent = cleanedReasoning || 'No detailed explanation was recorded for this entry. Review the student message directly.';
    explanationBlock.append(explanationTerm, explanationBox);
    panel.append(explanationBlock);

    const metaRows = [
        ['Analysis source', sources[vent.analysis_source] || sources.unknown],
    ];
    const trajectories = {
        ESCALATING: 'Escalating (repeated high-risk check-ins)',
        IMPROVING: 'Improving',
        STABLE: 'Stable',
    };
    if (trajectories[vent.trajectory]) {
        metaRows.push(['Recent 14-day pattern', trajectories[vent.trajectory]]);
    }

    metaRows.forEach(([label, value]) => {
        const block = document.createElement('div');
        block.className = 'mb-3 last:mb-0';
        const term = document.createElement('p');
        term.className = 'text-xs font-semibold text-[#435e51] mb-1';
        term.textContent = label;
        const detail = document.createElement('p');
        detail.className = 'text-sm text-[#203d30] leading-relaxed whitespace-pre-line';
        detail.textContent = value;
        block.append(term, detail);
        panel.append(block);
    });

    const note = document.createElement('p');
    note.className = 'text-xs text-[#435e51] border-t border-[#cfe0d5] pt-3 mt-1';
    const reminderLabel = document.createElement('strong');
    reminderLabel.className = 'font-semibold text-[#164a36]';
    reminderLabel.textContent = 'Clinical reminder: ';
    note.append(
        reminderLabel,
        'This automated screening is a triage aid only and does not replace a licensed psychologist, mental health professional, or clinical judgment. Always confirm meaning and context directly with the student before deciding on follow-up.'
    );
    panel.append(note);
}
