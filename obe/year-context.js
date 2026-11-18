/* Keep the authorised program-year preference with navigation, not only in a shared session. */
(() => {
    const scope=document.getElementById('obe-year-context');if(!scope)return;
    const year=scope.dataset.year;
    for(const anchor of document.querySelectorAll('a[href]')) {
        const url=new URL(anchor.href,location.href);
        if(url.origin===location.origin && url.pathname.includes('/local/obe/') && !url.pathname.endsWith('/set_tahun.php')) {
            url.searchParams.set('obe_year',year);anchor.href=url.href;
        }
    }
    for(const form of document.querySelectorAll('form')) {
        const url=new URL(form.action||location.href,location.href);
        if(url.origin!==location.origin || !url.pathname.includes('/local/obe/') || url.pathname.endsWith('/set_tahun.php'))continue;
        let input=form.querySelector('input[name="obe_year"]');
        if(!input){input=document.createElement('input');input.type='hidden';input.name='obe_year';form.append(input);}
        input.value=year;
    }
})();
