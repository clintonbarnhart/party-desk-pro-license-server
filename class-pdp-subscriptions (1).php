(function(){
    function initSignup(signup){
        var form=signup.querySelector('.pdp-multistep-form');
        if(!form)return;
        var panels=[].slice.call(form.querySelectorAll('[data-step-panel]'));
        var progress=[].slice.call(signup.querySelectorAll('.pdp-progress-step'));
        var selectedName=signup.querySelector('[data-selected-plan-name]');
        var selectedPrice=signup.querySelector('[data-selected-plan-price]');
        var message=signup.querySelector('.pdp-step-message');

        function selectedPlan(){return form.querySelector('.pdp-plan-card input[name="plan_id"]:checked');}
        function updatePlan(){
            var input=selectedPlan();
            form.querySelectorAll('.pdp-plan-card').forEach(function(card){card.classList.toggle('selected',!!input&&card.contains(input));});
            if(selectedName)selectedName.textContent=input?input.getAttribute('data-plan-name'):'Choose a plan';
            if(selectedPrice)selectedPrice.textContent=input?input.getAttribute('data-plan-price'):'';
            if(message&&input)message.textContent='';
        }
        function showStep(step,scroll){
            panels.forEach(function(panel){panel.classList.toggle('is-active',panel.getAttribute('data-step-panel')===String(step));});
            progress.forEach(function(item){
                var n=parseInt(item.getAttribute('data-go-step'),10);
                item.classList.toggle('is-active',n===step);
                item.classList.toggle('is-complete',n<step);
                item.setAttribute('aria-current',n===step?'step':'false');
            });
            signup.setAttribute('data-current-step',String(step));
            if(step===2)updatePlan();
            if(scroll!==false){
                var top=signup.getBoundingClientRect().top+window.pageYOffset-30;
                window.scrollTo({top:Math.max(0,top),behavior:'smooth'});
            }
        }
        signup.addEventListener('change',function(e){if(e.target.matches('.pdp-plan-card input'))updatePlan();});
        signup.addEventListener('click',function(e){
            var next=e.target.closest('.pdp-next-step');
            if(next){
                e.preventDefault();
                if(!selectedPlan()){
                    if(message)message.textContent='Please choose a plan before continuing.';
                    var first=form.querySelector('.pdp-plan-card');
                    if(first)first.scrollIntoView({behavior:'smooth',block:'center'});
                    return;
                }
                showStep(2,true);return;
            }
            var back=e.target.closest('.pdp-back-step,[data-go-step="1"]');
            if(back){e.preventDefault();showStep(1,true);return;}
            var go2=e.target.closest('[data-go-step="2"]');
            if(go2&&selectedPlan()){e.preventDefault();showStep(2,true);}
        });
        form.addEventListener('submit',function(e){
            if(!selectedPlan()){
                e.preventDefault();showStep(1,true);
                if(message)message.textContent='Please choose a plan before continuing.';
                return;
            }
            var invalid=form.querySelector('[data-step-panel="2"] input:invalid,[data-step-panel="2"] textarea:invalid');
            if(invalid){e.preventDefault();showStep(2,false);invalid.reportValidity();invalid.focus();}
        });
        updatePlan();
        showStep(parseInt(signup.getAttribute('data-initial-step')||'1',10),false);
    }
    function boot(){document.querySelectorAll('.pdp-signup').forEach(initSignup);}
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot);else boot();
})();
