document.addEventListener('DOMContentLoaded',function(){document.querySelectorAll('[data-pdp-confirm]').forEach(function(a){a.addEventListener('click',function(e){if(!confirm(a.getAttribute('data-pdp-confirm')))e.preventDefault();});});});

// Square secret-field visibility control.
document.addEventListener('click', function(event){
  var button = event.target.closest('[data-pdp-toggle-secret]');
  if(!button) return;
  var input = document.querySelector(button.getAttribute('data-pdp-toggle-secret'));
  if(!input) return;
  var showing = input.type === 'text';
  input.type = showing ? 'password' : 'text';
  button.textContent = showing ? 'Show' : 'Hide';
});

// Responsive Desktop/Mobile previews used by My Account and Signup Form builders.
document.addEventListener('DOMContentLoaded', function(){
  var frames = document.querySelectorAll('.pdp-responsive-preview, #pdp-portal-preview-frame');
  if(!frames.length) return;

  frames.forEach(function(frame){
    var frameId = frame.id;
    var switcher = document.querySelector('.pdp-device-switch[data-preview-target="' + frameId + '"]');
    if(!switcher){
      // Backward-compatible My Account builder markup.
      switcher = frame.closest('.pdp-pro-preview') && frame.closest('.pdp-pro-preview').querySelector('.pdp-device-switch');
    }
    var buttons = switcher ? switcher.querySelectorAll('[data-device]') : [];
    var stage = frame.querySelector('.pdp-preview-stage');
    var childSelector = frame.getAttribute('data-preview-child') || '.pdp-portal';
    var preview = stage && stage.querySelector(childSelector);
    if(!stage || !preview) return;

    function setDevice(device){
      frame.classList.toggle('is-desktop', device === 'desktop');
      frame.classList.toggle('is-mobile', device === 'mobile');
      buttons.forEach(function(button){
        var active = button.getAttribute('data-device') === device;
        button.classList.toggle('is-active', active);
        button.setAttribute('aria-pressed', active ? 'true' : 'false');
      });
      window.requestAnimationFrame(sizePreview);
    }

    function sizePreview(){
      var mobile = frame.classList.contains('is-mobile');
      var desktopWidth = parseInt(frame.getAttribute('data-preview-width-desktop') || (preview.classList.contains('pdp-signup') ? '1120' : '1180'), 10);
      var mobileWidth = parseInt(frame.getAttribute('data-preview-width-mobile') || '390', 10);
      var designWidth = mobile ? mobileWidth : desktopWidth;
      var available = Math.max(260, frame.clientWidth - 28);
      var maxDesktopScale = preview.classList.contains('pdp-signup') ? 0.78 : 0.72;
      var scale = mobile ? Math.min(1, available / designWidth) : Math.min(maxDesktopScale, available / designWidth);

      stage.style.setProperty('--pdp-preview-width', designWidth + 'px');
      stage.style.setProperty('--pdp-preview-scale', scale.toFixed(4));
      frame.style.setProperty('--pdp-preview-width', designWidth + 'px');
      frame.style.setProperty('--pdp-preview-scale', scale.toFixed(4));

      window.requestAnimationFrame(function(){
        var naturalHeight = Math.max(preview.scrollHeight, preview.offsetHeight);
        stage.style.height = Math.ceil(naturalHeight * scale) + 'px';
      });
    }

    buttons.forEach(function(button){
      button.addEventListener('click', function(){ setDevice(button.getAttribute('data-device')); });
    });

    window.addEventListener('resize', sizePreview);
    if('ResizeObserver' in window){ new ResizeObserver(sizePreview).observe(frame); }
    new MutationObserver(sizePreview).observe(frame, {subtree:true, childList:true, attributes:true, characterData:true});
    setDevice(frame.classList.contains('is-mobile') ? 'mobile' : 'desktop');
  });
});

// License email logo media uploader.
document.addEventListener('DOMContentLoaded', function(){
  var upload = document.querySelector('[data-pdp-email-logo-upload]');
  var remove = document.querySelector('[data-pdp-email-logo-remove]');
  var input = document.getElementById('pdp-license-email-logo');
  var preview = document.getElementById('pdp-email-logo-preview');
  if(!upload || !input || !preview || typeof wp === 'undefined' || !wp.media) return;
  var frame;
  function render(url){
    preview.classList.toggle('is-empty', !url);
    preview.innerHTML = url ? '<img src="' + url.replace(/"/g,'&quot;') + '" alt="Email logo preview">' : '<span class="dashicons dashicons-format-image"></span><strong>No logo selected</strong><small>Recommended: transparent PNG, up to 600 × 180 px</small>';
    if(remove) remove.style.display = url ? '' : 'none';
  }
  upload.addEventListener('click', function(e){
    e.preventDefault();
    if(frame){ frame.open(); return; }
    frame = wp.media({title:'Choose email logo',button:{text:'Use this logo'},multiple:false,library:{type:'image'}});
    frame.on('select', function(){ var item=frame.state().get('selection').first().toJSON(); input.value=item.url||''; render(input.value); });
    frame.open();
  });
  if(remove) remove.addEventListener('click', function(e){ e.preventDefault(); input.value=''; render(''); });
});

// v2.8.1 — Professional plan editor live preview.
document.addEventListener('DOMContentLoaded', function(){
  var editor = document.querySelector('.pdp-plan-editor-pro');
  var preview = document.getElementById('pdp-plan-preview');
  if(!editor || !preview) return;
  var title = document.getElementById('title');
  function field(name){ return editor.querySelector('[name="' + name + '"]'); }
  function billingSuffix(value){
    return {month:'/month','6-months':'/6 months',year:'/year','one-time':' one time',trial:''}[value] || ('/' + value);
  }
  function update(){
    var price = field('pdp_price');
    var billing = field('pdp_billing');
    var badge = field('pdp_badge');
    var description = field('pdp_description');
    var features = field('pdp_features');
    var button = field('pdp_button');
    var amount = parseFloat(price && price.value ? price.value : 0);
    preview.querySelector('h3').textContent = title && title.value.trim() ? title.value.trim() : 'Plan Name';
    preview.querySelector('.pdp-preview-badge').textContent = badge && badge.value.trim() ? badge.value.trim() : '';
    preview.querySelector('.pdp-preview-price').innerHTML = (billing && billing.value === 'trial' ? 'Free' : '$' + amount.toFixed(2)) + ' <span>' + billingSuffix(billing ? billing.value : 'month') + '</span>';
    preview.querySelector('.pdp-preview-desc').textContent = description && description.value.trim() ? description.value.trim() : 'Add a clear plan description for your customers.';
    preview.querySelector('button').textContent = button && button.value.trim() ? button.value.trim() : 'Choose Plan';
    var list = preview.querySelector('ul');
    list.innerHTML = '';
    var items = features ? features.value.split(/\r?\n/).map(function(v){return v.trim();}).filter(Boolean).slice(0,6) : [];
    if(!items.length) items = ['Add plan features to preview them here'];
    items.forEach(function(item){ var li=document.createElement('li'); li.textContent=item; list.appendChild(li); });
  }
  editor.addEventListener('input', update);
  editor.addEventListener('change', update);
  if(title) title.addEventListener('input', update);
  update();
});

jQuery(function($){
  $(document).on('click','.pdp-copy-button',function(){
    var button=$(this), value=button.data('copy')||'';
    if(navigator.clipboard){navigator.clipboard.writeText(value).then(function(){button.text('Copied');setTimeout(function(){button.text('Copy');},1400);});}
    else{var input=button.siblings('input').get(0);if(input){input.select();document.execCommand('copy');button.text('Copied');}}
  });
});
