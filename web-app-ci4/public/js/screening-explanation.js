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
    const sources = {model: 'ML service with PHP safety rules', local: 'Local keyword fallback', unknown: 'Source unavailable for this entry'};
    const rows = [
        ['Review priority', `${vent.risk_level || 'Unavailable'} · Recorded score ${vent.risk_percent ?? '—'}%`],
        ['Analysis source', sources[vent.analysis_source] || sources.unknown],
        ['Recorded explanation', vent.screening_analysis?.reasoning || 'An explanation was not recorded for this entry. Review the original message with the student.'],
    ];
    const trajectories = {ESCALATING: 'Escalating', IMPROVING: 'Improving', STABLE: 'Stable'};
    if (trajectories[vent.trajectory]) rows.push(['Recorded recent pattern', trajectories[vent.trajectory]]);
    rows.forEach(([label, value]) => {
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
    note.className = 'text-xs text-[#435e51] border-t border-[#cfe0d5] pt-3';
    note.textContent = 'The screening score supports counselor review. Confirm the meaning and context of the message with the student before deciding on follow-up.';
    panel.append(note);
}
