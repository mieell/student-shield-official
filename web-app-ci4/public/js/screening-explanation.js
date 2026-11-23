const STUDENT_FEELING_EMOJIS = {
    okay: '😐',
    calm: '😌',
    happy: '😊',
    hopeful: '🙂',
    sad: '😔',
    angry: '😠',
    anxious: '😰',
    lonely: '🥺',
    overwhelmed: '😵',
    hurt: '🤕',
    numb: '😶',
    scared: '😨',
};

function parseStudentEmotions(raw) {
    const items = Array.isArray(raw)
        ? raw
        : (typeof raw === 'string' ? raw.split(',') : []);
    const seen = new Set();
    const list = [];
    items.forEach(item => {
        const label = String(item || '').replace(/\s+/g, ' ').trim();
        const key = label.toLowerCase();
        if (!label || seen.has(key)) return;
        seen.add(key);
        list.push({
            label,
            emoji: STUDENT_FEELING_EMOJIS[key] || '',
        });
    });
    return list;
}

function splitVentFollowUp(rawText) {
    const text = typeof rawText === 'string' ? rawText : '';
    const match = /\r?\n\r?\nAdditional context:\s*([\s\S]+)$/i.exec(text);
    if (!match) {
        return { mainText: text, followUpText: '' };
    }
    return {
        mainText: text.slice(0, match.index).trim(),
        followUpText: match[1].trim(),
    };
}

function cleanScreeningText(raw, finalRisk = '') {
    if (typeof raw !== 'string') return '';
    const riskUpper = String(finalRisk || '').toUpperCase();
    if (riskUpper === 'CRITICAL' && raw.includes('The PHP safety rules raised the final review priority after the initial analysis.')) {
        return 'A safety rule detected wording that raised the review priority to Critical. Review the full entry and confirm the context with the student.';
    }
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
    const modalContent = document.getElementById('modalContent');
    if (!modalContent) return;

    document.getElementById('modalFollowUpContext')?.remove();
    document.getElementById('modalStudentFeelings')?.remove();

    let anchor = modalContent;

    const { mainText, followUpText } = splitVentFollowUp(vent.raw_text);
    if (followUpText) {
        modalContent.textContent = mainText || vent.raw_text || '';
        const followUpSection = document.createElement('section');
        followUpSection.id = 'modalFollowUpContext';
        followUpSection.className = 'mt-4 border border-[#e7d9a7] rounded-xl p-4 bg-[#fffcf0]';

        const followUpHeading = document.createElement('h4');
        followUpHeading.className = 'text-xs font-bold uppercase tracking-wider text-[#8a6500] mb-1.5';
        followUpHeading.textContent = 'Student follow-up context';

        const followUpBody = document.createElement('p');
        followUpBody.className = 'text-sm text-[#2d3b32] leading-relaxed whitespace-pre-wrap';
        followUpBody.textContent = followUpText;

        followUpSection.append(followUpHeading, followUpBody);
        anchor.after(followUpSection);
        anchor = followUpSection;
    }

    const feelingsSection = document.createElement('section');
    feelingsSection.id = 'modalStudentFeelings';
    feelingsSection.className = 'mt-4 border border-[#d8e3db] rounded-xl p-3.5 bg-white';

    const feelingsHeading = document.createElement('h4');
    feelingsHeading.className = 'text-xs font-bold uppercase tracking-wider text-[#235D3A] mb-2';
    feelingsHeading.textContent = 'Student-selected feelings';
    feelingsSection.append(feelingsHeading);

    const parsedFeelings = parseStudentEmotions(vent.emotions);
    if (parsedFeelings.length > 0) {
        const chipList = document.createElement('div');
        chipList.className = 'flex flex-wrap gap-2';
        parsedFeelings.forEach(({ label, emoji }) => {
            const chip = document.createElement('span');
            chip.className = 'inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-[#fff4cf] text-[#203d2c] border border-[#ebd794]';
            if (emoji) {
                const emojiSpan = document.createElement('span');
                emojiSpan.setAttribute('aria-hidden', 'true');
                emojiSpan.textContent = emoji;
                chip.append(emojiSpan);
            }
            const textSpan = document.createElement('span');
            textSpan.textContent = label;
            chip.append(textSpan);
            chipList.append(chip);
        });
        feelingsSection.append(chipList);
    } else {
        const emptyNote = document.createElement('p');
        emptyNote.className = 'text-xs text-[#61756B]';
        emptyNote.textContent = 'None selected by student';
        feelingsSection.append(emptyNote);
    }
    anchor.after(feelingsSection);
    anchor = feelingsSection;

    let panel = document.getElementById('screeningExplanation');
    if (!panel) {
        panel = document.createElement('section');
        panel.id = 'screeningExplanation';
        panel.className = 'mt-4 border border-[#cfe0d5] rounded-xl p-4 bg-[#f4f8f5]';
    }
    anchor.after(panel);
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
        MEDIUM: 'bg-blue-100 text-blue-800 border-blue-200',
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
    const severityLegend = document.getElementById('screeningSeverityLegend');
    if (severityLegend) priorityBlock.append(severityLegend.content.cloneNode(true));
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
    explanationBox.textContent = cleanedReasoning || (riskLevel === 'CRITICAL'
        ? 'Critical priority was recorded. A detailed reason is unavailable for this entry. Review the full message promptly.'
        : 'No detailed explanation was recorded for this entry. Review the student message directly.');
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
