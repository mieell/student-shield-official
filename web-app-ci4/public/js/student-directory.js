document.querySelectorAll('.student-picker').forEach((picker) => {
    const grade = picker.querySelector('.directory-grade');
    const section = picker.querySelector('.directory-section');
    const search = picker.querySelector('.directory-search');
    const choice = picker.querySelector('.directory-choice');
    const description = picker.querySelector('.directory-description');
    const descriptionLabel = picker.querySelector('.directory-description-label');
    const status = picker.querySelector('.directory-status');
    const popup = picker.querySelector('.directory-popup');
    const matches = picker.querySelector('.directory-matches');
    let timer;
    let requestVersion = 0;

    function resetChoices() {
        clearTimeout(timer);
        matches.replaceChildren();
        popup.hidden = true;
        choice.replaceChildren(new Option('Select a student', ''), new Option("I don't know / not listed", 'unknown'));
        description.value = '';
        description.hidden = true;
        description.disabled = true;
        description.required = false;
        descriptionLabel.hidden = true;
    }

    grade.addEventListener('change', () => {
        requestVersion++;
        resetChoices();
        section.replaceChildren(new Option('Choose section', ''));
        (directorySections[grade.value] || []).forEach((name) => section.add(new Option(name, name)));
        section.disabled = !grade.value;
        search.value = '';
        search.disabled = true;
        status.textContent = 'Choose a section, or select “not listed” if the person is unavailable.';
    });
    section.addEventListener('change', () => {
        requestVersion++;
        resetChoices();
        search.disabled = !section.value;
        search.value = '';
        status.textContent = 'Enter at least two letters to search this grade and section.';
    });
    search.addEventListener('input', () => {
        const version = ++requestVersion;
        resetChoices();
        if (search.value.trim().length < 2) {
            status.textContent = 'Enter at least two letters.';
            return;
        }
        status.textContent = 'Searching…';
        timer = setTimeout(async () => {
            const query = new URLSearchParams({grade: grade.value, section: section.value, q: search.value.trim()});
            try {
                const response = await fetch(`${studentDirectoryUrl}?${query}`, {credentials: 'same-origin', cache: 'no-store'});
                if (!response.ok) throw new Error('Search unavailable');
                const result = await response.json();
                if (version !== requestVersion) return;
                result.students.forEach((student) => {
                    const id = String(student.id);
                    choice.add(new Option(student.name, id));

                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'directory-match';
                    button.textContent = student.name;
                    button.addEventListener('click', () => {
                        choice.value = id;
                        choice.dispatchEvent(new Event('change'));
                        choice.focus();
                    });
                    matches.append(button);
                });
                popup.hidden = result.students.length === 0;
                status.textContent = result.students.length ? 'Choose the matching student. Add more letters if needed.' : 'No students found. Check the grade and section, or select “not listed”.';
            } catch (error) {
                if (version === requestVersion) status.textContent = 'Search is unavailable. Try again or select “not listed” and describe the person.';
            }
        }, 150);
    });
    choice.addEventListener('change', () => {
        popup.hidden = true;
        const unknown = choice.value === 'unknown';
        descriptionLabel.hidden = !unknown;
        description.hidden = !unknown;
        description.disabled = !choice.value;
        description.required = unknown;
        description.value = unknown ? '' : (choice.value ? choice.selectedOptions[0].textContent : '');
        if (unknown) description.focus();
    });
});
