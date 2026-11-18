/* Complete label associations for legacy forms without changing form names or values. */
(() => {
    let sequence=0;
    for(const label of document.querySelectorAll('.obe-workspace label')) {
        if(label.control || label.htmlFor || label.querySelector('input,select,textarea'))continue;
        const group=label.closest('.form-group') || label.parentElement;
        const control=group?.querySelector('input:not([type="hidden"]),select,textarea');
        if(control){if(!control.id)control.id=`obe-field-${++sequence}`;label.htmlFor=control.id;}
    }
    for(const button of document.querySelectorAll('.obe-workspace button,.obe-workspace a.btn')) {
        if(!button.textContent.trim() && button.title)button.setAttribute('aria-label',button.title);
    }
    // Stack simple legacy tables; leave complex multi-level headers scrollable rather than mislabel them.
    for(const table of document.querySelectorAll('.obe-workspace .table-responsive > table.table:not(.obe-student-report)')) {
        const headers=[...table.querySelectorAll(':scope > thead > tr:last-child > th')];
        if(!headers.length || headers.some(h=>h.colSpan>1 || h.rowSpan>1))continue;
        table.classList.add('obe-stack-table');
        for(const row of table.querySelectorAll(':scope > tbody > tr')) {
            const cells=[...row.cells];if(cells.length!==headers.length || cells.some(c=>c.colSpan>1 || c.rowSpan>1))continue;
            row.classList.add('obe-stack-row');
            cells.forEach((cell,i)=>{cell.dataset.label=headers[i].textContent.trim();});
        }
    }
})();
