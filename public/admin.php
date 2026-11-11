<?php elseif($section==='media-management'):?>
  <style>
    .page-management-tabs{display:flex;align-items:center;gap:7px;flex-wrap:wrap;margin:20px 0 0;padding:0 0 12px;border-bottom:1px solid var(--line)}
    .page-management-tabs button{appearance:none;border:1px solid transparent;border-radius:10px;padding:10px 17px;background:transparent;color:#c8bec1;font:500 13px 'Ubuntu',sans-serif;cursor:pointer;transition:background .2s,border-color .2s,color .2s,box-shadow .2s}
    .page-management-tabs button:hover{background:rgba(255,255,255,.045);color:#fff}
    .page-management-tabs button[aria-selected="true"]{color:#fff;background:linear-gradient(110deg,rgba(128,20,39,.48),rgba(30,12,18,.62));border-color:rgba(184,50,63,.4);box-shadow:inset 1px 1px 3px rgba(255,255,255,.04),0 4px 12px rgba(0,0,0,.14)}
    .page-management-tabpanel{margin-top:20px;min-height:190px}
    .page-management-tabpanel[hidden]{display:none}
  </style>
  <section class="panel admin-panel">
    <div class="admin-panel-head"><div><h2>Media Management</h2><p class="muted">Manage photographs, image collections, and videos displayed throughout the public website.</p></div></div>
  <nav class="page-management-tabs" role="tablist" aria-label="Media Management tabs" data-initial-tab="<?=e($activeMediaTab)?>">
    <button type="button" id="media-tab-gallery" role="tab" aria-controls="media-panel-gallery" aria-selected="false" tabindex="-1" data-media-tab="gallery">Gallery</button>
    <button type="button" id="media-tab-video" role="tab" aria-controls="media-panel-video" aria-selected="false" tabindex="-1" data-media-tab="video">Video</button>
  </nav>
  <section class="page-management-tabpanel" id="media-panel-gallery" role="tabpanel" aria-labelledby="media-tab-gallery">
    <h2>Gallery</h2><p class="muted">Manage photographs and image collections displayed on the public website.</p>
    <div class="admin-note">Gallery management controls can be added here. No existing website content has been changed.</div>
  </section>
  <section class="page-management-tabpanel" id="media-panel-video" role="tabpanel" aria-labelledby="media-tab-video" hidden>
    <h2>Video</h2><p class="muted">Manage videos displayed on the public website.</p>
    <div class="admin-note">Video management controls can be added here. No existing website content has been changed.</div>
  </section>
  <script>
  (() => {
    const nav=document.querySelector('.page-management-tabs');
    if(!nav||nav.dataset.handlersReady==='1')return;
    nav.dataset.handlersReady='1';
    const tabs=[...nav.querySelectorAll('[data-media-tab]')];
    const activate=(tab,focus=false)=>{
      tabs.forEach(item=>{
        const selected=item===tab;
        item.setAttribute('aria-selected',String(selected));
        item.tabIndex=selected?0:-1;
        const panel=document.getElementById(item.getAttribute('aria-controls'));
        if(panel)panel.hidden=!selected;
      });
      if(focus)tab.focus();
    };
    const initial=nav.dataset.initialTab||'gallery';
    activate(tabs.find(tab=>tab.dataset.mediaTab===initial)||tabs[0]);
    tabs.forEach((tab,index)=>{
      tab.addEventListener('click',()=>activate(tab));
      tab.addEventListener('keydown',event=>{
        let next=index;
        if(event.key==='ArrowRight')next=(index+1)%tabs.length;
        else if(event.key==='ArrowLeft')next=(index-1+tabs.length)%tabs.length;
        else if(event.key==='Home')next=0;
        else if(event.key==='End')next=tabs.length-1;
        else return;
        event.preventDefault();activate(tabs[next],true);
      });
    });
  })();
  </script>
  </section>
<?php elseif($section==='page-management'): ?>
    <style>
      .page-management-tabs{display:flex;align-items:center;gap:7px;flex-wrap:wrap;margin:20px 0 0;padding:0 0 12px;border-bottom:1px solid var(--line)}
      .page-management-tabs button{appearance:none;border:1px solid transparent;border-radius:10px;padding:10px 17px;background:transparent;color:#c8bec1;font:500 13px 'Ubuntu',sans-serif;cursor:pointer;transition:background .2s,border-color .2s,color .2s,box-shadow .2s}
      .page-management-tabs button:hover{background:rgba(255,255,255,.045);color:#fff}
      .page-management-tabs button[aria-selected="true"]{color:#fff;background:linear-gradient(110deg,rgba(128,20,39,.48),rgba(30,12,18,.62));border-color:rgba(184,50,63,.4);box-shadow:inset 1px 1px 3px rgba(255,255,255,.04),0 4px 12px rgba(0,0,0,.14)}
      .page-management-tabpanel{margin-top:20px;min-height:190px}
      .page-management-tabpanel[hidden]{display:none}
      .header-settings-grid{display:grid;grid-template-columns:minmax(0,1.25fr) minmax(280px,.85fr);gap:24px;align-items:start}
      .header-settings-fields{display:grid;gap:20px;min-width:0}.header-field{display:grid;gap:8px;color:#f4e9ec;font-size:13px;font-weight:600}.header-field b{color:#ff8799}.header-field small,.header-preview-help{font-size:11px;color:var(--muted);font-weight:400;line-height:1.55}.header-field .field{width:100%;box-sizing:border-box;background:#171115;color:#f5e9ed;border:1px solid var(--line);border-radius:9px;padding:10px 12px;font:13px 'Ubuntu',sans-serif}
      .header-link-row{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr) auto;gap:8px;margin:8px 0}
      .rich-editor{display:block;width:100%;min-height:38px;padding:9px 11px;border:1px solid var(--line);border-radius:8px;background:#120d10;color:#f4e9ec;font:13px Ubuntu,sans-serif;outline:none;box-sizing:border-box}.header-title-source{min-height:58px;resize:vertical;white-space:pre-wrap}.rich-editor:focus{border-color:#a83b52;box-shadow:0 0 0 2px rgba(168,59,82,.16)}.rich-tools{display:flex;gap:6px;flex-wrap:wrap;margin-top:7px}.font-format-tools{display:flex;align-items:center;gap:5px}.font-format-tools .format-icon{width:32px;height:30px;border:1px solid var(--line);border-radius:6px;background:#171115;color:#f4e9ec;cursor:pointer;font:13px Ubuntu,sans-serif}.font-format-tools .format-icon[aria-pressed="true"]{background:#7f1d34;border-color:#bd536a;color:#fff;box-shadow:inset 0 0 0 1px #bd536a}.rich-style-controls{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:7px;grid-column:1/-1}.rich-style-controls label{display:grid;gap:4px;font-size:10px;color:#cdbdc2}.rich-style-controls input,.rich-style-controls select{width:100%;min-width:0;padding:6px;background:#171115;color:#fff;border:1px solid var(--line);border-radius:6px;font:11px Ubuntu,sans-serif}.rich-tools button{padding:5px 8px;border:1px solid var(--line);border-radius:6px;background:#21151a;color:#f4e9ec;font-size:11px;cursor:pointer}.rich-tools input,.rich-tools select{max-width:110px;padding:4px;background:#171115;color:#fff;border:1px solid var(--line);border-radius:6px}.typography-group{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;border:1px solid var(--line);border-radius:10px;padding:10px}.typography-group legend{font-size:12px;color:#f1c4ce;padding:0 5px}.typography-group label{display:grid;gap:5px;font-size:11px;color:#d9cbd0}.typography-group .field{min-width:0;padding:8px}.header-link-row{align-items:start}.header-hidden-fields,.header-typography-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.header-hidden-item{display:grid;gap:8px;min-width:0;color:#d9cbd0;font-size:12px}.header-hidden-fields label,.header-typography-fields label,.logo-crop-controls label{display:grid;gap:7px;font-size:12px;color:#d9cbd0}.logo-crop-controls{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:10px}.logo-crop-controls input{width:100%;accent-color:#a52b45}
      .header-preview-column{position:relative;min-width:0;margin-bottom:24px}.header-preview-title{font-size:13px;font-weight:700;margin-bottom:10px}.header-preview-frame{overflow:hidden;border:1px solid rgba(184,50,63,.35);border-radius:13px;background:#100d10;box-shadow:0 12px 35px rgba(0,0,0,.22)}.header-preview-frame,.header-preview-frame *{pointer-events:none!important;user-select:none!important;cursor:default!important}.header-preview-topbar{display:flex;justify-content:space-between;align-items:center;gap:16px;padding:10px 22px;background:#09090b;color:#b6c0b8;border-bottom:1px solid rgba(255,255,255,.1);font:10px Ubuntu,sans-serif;letter-spacing:normal;text-transform:none}.header-preview-topbar span{max-width:48%;overflow-wrap:anywhere}.header-preview-topbar span:last-child{text-align:right}.header-preview-main{display:flex;align-items:center;justify-content:space-between;gap:28px;padding:0 22px;min-height:82px;background:rgba(11,11,13,.92);border-bottom:1px solid rgba(255,255,255,.1);backdrop-filter:blur(18px)} .header-preview-brand{display:flex;align-items:center;gap:12px;color:#f7f2f3;text-decoration:none;min-width:0}.header-preview-brand img{display:block;flex:0 0 auto;width:200px;height:70px;max-width:100%;object-fit:contain;object-position:center;border-radius:0}.header-preview-brand span{display:none!important}.header-preview-main nav{display:flex;align-items:center;justify-content:flex-end;gap:19px;flex-wrap:wrap;font-size:12px;color:#c1cbc3}.header-preview-main nav a{font-size:12px;color:#c1cbc3;text-decoration:none;white-space:nowrap}.header-preview-main nav .preview-signin{color:#c1cbc3}.header-preview-main nav .preview-join{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:12px 19px;border:1px solid #b8323f;border-radius:999px;background:#b8323f;color:#101611;font-size:12px;font-weight:750;letter-spacing:.02em;box-shadow:0 8px 24px rgba(184,50,63,.15)}.header-preview-help{margin:10px 0 0}.header-settings-actions{display:flex;justify-content:flex-end;margin-top:22px;padding-top:17px;border-top:1px solid var(--line)}
      @media(max-width:900px){.header-settings-grid{grid-template-columns:1fr}.header-preview-column{position:static}}
      @media(max-width:560px){.header-link-row{grid-template-columns:1fr}.header-hidden-fields,.header-typography-fields,.logo-crop-controls{grid-template-columns:1fr} .header-preview-main{align-items:flex-start;flex-direction:column}.header-hidden-copy{max-width:100%;overflow-wrap:anywhere}.header-preview-main nav{justify-content:flex-start}}
      .footer-settings-grid{display:grid;grid-template-columns:minmax(0,1fr);gap:24px;align-items:start}
      .footer-preview-column{position:relative;top:auto;min-width:0;width:100%}.footer-preview-frame{overflow:hidden;border:1px solid var(--line);border-radius:12px;background:#0d090b;padding:24px 18px;color:#eee}
      .footer-preview-frame .footer-grid{display:grid;grid-template-columns:1.35fr 1fr 1fr;gap:22px;max-width:none;width:100%;padding:0}
      .footer-preview-frame .brand,.footer-preview-frame b{display:block;margin:0 0 12px;color:#f0dadd;font-size:11px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;text-decoration:none}
      .footer-preview-frame .brand span{color:#c33d59}.footer-preview-frame p{color:#b9aeb2;font-size:11px;line-height:1.6;margin:8px 0}
      .footer-preview-frame #preview-footer-explore-links,.footer-preview-frame #preview-footer-discover-links{display:grid;gap:8px}
      .footer-preview-frame a:not(.brand){display:block;color:#c9bbc0;text-decoration:none;font-size:11px;line-height:1.45}
      .footer-settings-fields{display:grid;gap:16px;min-width:0}.footer-column-editor{display:grid;gap:10px;padding:15px;border:1px solid var(--line);border-radius:11px;background:rgba(255,255,255,.018)}
      .footer-column-editor h3{font:600 14px Ubuntu,sans-serif;margin:0;color:#f4e9ec}.footer-column-editor .field{width:100%;box-sizing:border-box;background:#171115;color:#f5e9ed;border:1px solid var(--line);border-radius:9px;padding:9px 11px;font:12px Ubuntu,sans-serif}
      .footer-column-editor textarea{resize:vertical}.footer-preview-frame .footer-preview-text-item{display:block;color:#c9bbc0;font-size:11px;line-height:1.45}.footer-field-invalid{border-color:#ff526b!important;box-shadow:0 0 0 2px rgba(255,82,107,.2)!important}.footer-link-row{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr) auto;gap:7px;margin:7px 0}
      .footer-column-editor .header-field{font-size:12px}.footer-column-editor small{font-size:10px;color:var(--muted);font-weight:400}
      @media(max-width:1000px){.footer-settings-grid{grid-template-columns:1fr}.footer-preview-column{position:static}}
      @media(max-width:600px){.footer-preview-frame .footer-grid{grid-template-columns:1fr;gap:16px}.footer-link-row{grid-template-columns:1fr}.footer-preview-frame{padding:18px 14px}}
      @media(max-width:560px){.page-management-tabs{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))}.page-management-tabs button{width:100%}}
    </style>
    <section class="panel admin-panel">
      <div class="admin-panel-head"><div><h2>Page Management</h2><p class="muted">Manage the content and presentation of your public website.</p></div></div>
      <div class="page-management-tabs" role="tablist" aria-label="Page Management tabs" data-initial-tab="<?=e($activePageTab)?>">
        <button type="button" id="pm-tab-header" role="tab" aria-controls="pm-panel-header" aria-selected="true" tabindex="0" data-page-tab="header">Header</button>
        <button type="button" id="pm-tab-footer" role="tab" aria-controls="pm-panel-footer" aria-selected="false" tabindex="-1" data-page-tab="footer">Footer</button>
        <button type="button" id="pm-tab-contents" role="tab" aria-controls="pm-panel-contents" aria-selected="false" tabindex="-1" data-page-tab="contents">Contents</button>
        <button type="button" id="pm-tab-sections" role="tab" aria-controls="pm-panel-sections" aria-selected="false" tabindex="-1" data-page-tab="sections">Sections</button>
      </div>
      <script>
      (() => {
        const tabNav = document.querySelector('.page-management-tabs');
        if (!tabNav || tabNav.dataset.handlersReady === '1') return;
        tabNav.dataset.handlersReady = '1';
        const tabs = [...tabNav.querySelectorAll('[data-page-tab]')];
        const activate = (tab, moveFocus = false) => {
          tabs.forEach(item => {
            const selected = item === tab;
            item.setAttribute('aria-selected', String(selected));
            item.tabIndex = selected ? 0 : -1;
            const panel = document.getElementById(item.getAttribute('aria-controls'));
            if (panel) panel.hidden = !selected;
          });
          if (moveFocus) tab.focus();
        };
        const initial = tabNav.dataset.initialTab || 'header';
        const activateInitialTab = () => activate(tabs.find(tab => tab.dataset.pageTab === initial) || tabs[0]);
        if (document.readyState === 'loading') {
          document.addEventListener('DOMContentLoaded', activateInitialTab, { once: true });
        } else {
          activateInitialTab();
        }
        tabs.forEach((tab, index) => {
          tab.addEventListener('click', () => activate(tab));
          tab.addEventListener('keydown', event => {
            let next = index;
            if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
            else if (event.key === 'ArrowLeft') next = (index - 1 + tabs.length) % tabs.length;
            else if (event.key === 'Home') next = 0;
            else if (event.key === 'End') next = tabs.length - 1;
            else return;
            event.preventDefault();
            activate(tabs[next], true);
          });
        });
      })();
      </script>
      <section class="page-management-tabpanel" id="pm-panel-header" role="tabpanel" aria-labelledby="pm-tab-header">
        <form method="post" enctype="multipart/form-data" id="header-settings-form" class="header-settings-form">
          <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
          <input type="hidden" name="action" value="save_header_settings">
          <aside class="header-preview-column"><div class="header-preview-title">Header Preview</div><div class="header-preview-frame" inert aria-label="Non-interactive visual preview of the public website header"><div class="header-preview-topbar"><span id="preview-hidden-left"><?=e((string)($headerSettings['hidden_left'] ?? ''))?></span><span id="preview-hidden-right"><?=e((string)($headerSettings['hidden_right'] ?? ''))?></span></div><div class="header-preview-main"><a href="../index.php" class="header-preview-brand" id="preview-header-brand"><img id="preview-header-logo" src="<?=e((string)($headerSettings['logo_path'] ?? ''))?>" alt="" style="width:<?=(int)($headerSettings['logo_width'] ?? 200)?>px;height:<?=(int)($headerSettings['logo_height'] ?? 70)?>px;object-fit:contain;<?=empty($headerSettings['logo_path'])?'display:none;':''?>"><span id="preview-header-title" hidden></span></a><nav id="preview-header-links"><?php foreach($headerLinks as $headerLink): if (!is_array($headerLink) || trim((string)($headerLink['label'] ?? '')) === '') continue; ?><a class="custom-preview-link" href="<?=e(trim((string)($headerLink['url'] ?? '')) ?: '#')?>"><?=e((string)$headerLink['label'])?></a><?php endforeach; ?><a href="#" class="preview-signin">Sign in</a><a href="#" class="preview-join">Join the Community</a></nav></div></div><p class="header-preview-help">Preview updates as you edit. Sign in and Join the Community stay visible on the public header.</p></aside>
          <div class="header-settings-grid">
            <div class="header-settings-fields">
              <div class="header-field" hidden aria-hidden="true"><input type="hidden" data-rich-editor="title" value="<?=e((string)($headerSettings['header_title'] ?? 'Z.C. commonplace'))?>"><input type="hidden" name="header_title" maxlength="160" data-plain-value="title" value="<?=e((string)($headerSettings['header_title'] ?? 'Z.C. commonplace'))?>"><input type="hidden" name="header_title_html" data-rich-value="title"></div>
              <div class="header-field"><span>Header Logo</span><input class="field" type="file" name="header_logo" id="header-logo-input" accept="image/png,image/jpeg"><small>Allowed filetype: PNG, JPG, or JPEG. Maximum upload is 3MB.</small>
                <div class="logo-crop-controls"><label>Logo width: <output id="logo-width-value"><?=(int)($headerSettings['logo_width'] ?? 200)?> px</output><input type="range" name="logo_width" id="logo-width" min="50" max="200" step="5" value="<?=max(50,min(200,(int)($headerSettings['logo_width'] ?? 200)))?>"></label><label>Logo height: <output id="logo-height-value"><?=(int)($headerSettings['logo_height'] ?? 70)?> px</output><input type="range" name="logo_height" id="logo-height" min="30" max="120" step="5" value="<?=max(30,min(120,(int)($headerSettings['logo_height'] ?? 70)))?>"></label></div>
              </div>
              <div class="header-field"><span>Header Links</span><small>Add as many navigation links as needed. Each URL can point directly to a website page.</small>
                <div id="header-links-list">
                <?php foreach($headerLinks as $headerLink): ?><div class="header-link-row"><input class="field" type="text" name="header_link_url[]" maxlength="500" placeholder="Page URL (e.g. /about.php)" value="<?=e((string)($headerLink['url'] ?? ''))?>"><input class="field" type="text" name="header_link_label[]" maxlength="60" placeholder="Link title" aria-label="Link title" value="<?=e((string)($headerLink['label'] ?? ''))?>"><button type="button" class="btn light remove-header-link" aria-label="Remove link">Remove</button></div><?php endforeach; ?>
                </div><button type="button" class="btn light" id="add-header-link">+ Add Header Link</button>
              </div>
              <div class="header-field"><span>Hidden Texts</span><small>Left Text appears in the far-left corner and Right Text in the far-right corner of the header.</small><div class="header-hidden-fields"><div class="header-hidden-item"><span>Left text</span><div class="rich-editor" data-rich-editor="hidden_left" contenteditable="true" role="textbox" aria-label="Edit left hidden text" data-placeholder="Text on the left"><?=cleanHeaderRichHtml((string)($headerSettings['hidden_left_html'] ?? '')) ?: e((string)($headerSettings['hidden_left'] ?? ''))?></div><input type="hidden" name="hidden_left" maxlength="180" data-plain-value="hidden_left" value="<?=e((string)($headerSettings['hidden_left'] ?? ''))?>"><input type="hidden" name="hidden_left_html" data-rich-value="hidden_left"></div><div class="header-hidden-item"><span>Right text</span><div class="rich-editor" data-rich-editor="hidden_right" contenteditable="true" role="textbox" aria-label="Edit right hidden text" data-placeholder="Text on the right"><?=cleanHeaderRichHtml((string)($headerSettings['hidden_right_html'] ?? '')) ?: e((string)($headerSettings['hidden_right'] ?? ''))?></div><input type="hidden" name="hidden_right" maxlength="180" data-plain-value="hidden_right" value="<?=e((string)($headerSettings['hidden_right'] ?? ''))?>"><input type="hidden" name="hidden_right_html" data-rich-value="hidden_right"></div></div></div>
            </div>
            
          </div>
          <div class="header-settings-actions"><button type="submit" class="btn">Save Header Settings</button></div>
        </form>
      </section>
      <section class="page-management-tabpanel" id="pm-panel-footer" role="tabpanel" aria-labelledby="pm-tab-footer" hidden>
        <form method="post" id="footer-settings-form" class="footer-settings-form">
          <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
          <input type="hidden" name="action" value="save_footer_settings">
          <div class="footer-settings-grid">
            <aside class="footer-preview-column">
              <div class="header-preview-title">Footer Preview</div>
              <div class="footer-preview-frame" inert aria-label="Non-interactive live preview of the public website footer">
                <div class="wrap footer-grid">
                  <div class="footer-preview-col"><b id="preview-footer-brand"><?=e($footerSettings['brand'])?></b><p id="preview-footer-description"><?=e($footerSettings['description'])?></p><p id="preview-footer-copyright"><?=e(str_replace('{year}',date('Y'),$footerSettings['copyright']))?></p><div id="preview-footer-brand-links"><?php foreach(($footerSettings['brand_links'] ?? []) as $link): ?><?php if(trim((string)($link['url'] ?? '')) !== ''): ?><a href="<?=e($link['url'])?>"><?=e($link['label'])?></a><?php else: ?><span class="footer-preview-text-item"><?=e($link['label'])?></span><?php endif; ?><?php endforeach; ?></div></div>
                  <div class="footer-preview-col"><b id="preview-footer-explore-title"><?=e($footerSettings['explore_title'])?></b><div id="preview-footer-explore-links"><?php foreach($footerSettings['explore_links'] as $link): ?><a href="<?=e($link['url'])?>"><?=e($link['label'])?></a><?php endforeach; ?></div></div>
                  <div class="footer-preview-col"><b id="preview-footer-discover-title"><?=e($footerSettings['discover_title'])?></b><div id="preview-footer-discover-links"><?php foreach($footerSettings['discover_links'] as $link): ?><a href="<?=e($link['url'])?>"><?=e($link['label'])?></a><?php endforeach; ?></div></div>
                </div>
              </div>
              <p class="header-preview-help">Preview updates as you edit.</p>
            </aside>
            <div class="footer-settings-fields">
              <div class="footer-column-editor"><h3>Column 1 — Brand</h3>
                <label class="header-field">Brand title<input class="field" name="footer_brand" id="footer-brand" maxlength="100" value="<?=e($footerSettings['brand'])?>"></label>
                <label class="header-field">Description<textarea class="field" name="footer_description" id="footer-description" rows="3" maxlength="500"><?=e($footerSettings['description'])?></textarea></label>
                <label class="header-field">Copyright text<textarea class="field" name="footer_copyright" id="footer-copyright" rows="2" maxlength="300"><?=e($footerSettings['copyright'])?></textarea><small>Use {year} to insert the current year automatically.</small></label>
                <div class="footer-link-editor" id="footer-brand-editor"><?php foreach(($footerSettings['brand_links'] ?? []) as $link): ?><div class="footer-link-row"><input class="field" name="brand_label[]" maxlength="100" aria-label="Brand item title" placeholder="Item title" value="<?=e($link['label'])?>"><input class="field" name="brand_url[]" maxlength="500" aria-label="Brand item URL" placeholder="Page URL (optional)" value="<?=e($link['url'])?>"><button type="button" class="btn light remove-footer-link">Remove</button></div><?php endforeach; ?></div>
              </div>
              <div class="footer-column-editor"><h3>Column 2 — Explore</h3>
                <label class="header-field">Column title<input class="field" name="footer_explore_title" id="footer-explore-title" maxlength="60" value="<?=e($footerSettings['explore_title'])?>"></label>
                <div class="footer-link-editor" id="footer-explore-editor"><?php foreach($footerSettings['explore_links'] as $link): ?><div class="footer-link-row"><input class="field" name="explore_label[]" maxlength="100" aria-label="Explore link title" placeholder="Link title" value="<?=e($link['label'])?>"><input class="field" name="explore_url[]" maxlength="500" aria-label="Explore link URL" placeholder="Page URL" value="<?=e($link['url'])?>"><button type="button" class="btn light remove-footer-link">Remove</button></div><?php endforeach; ?></div>
                <button type="button" class="btn light add-footer-link" data-group="explore">+ Add Explore Link</button>
              </div>
              <div class="footer-column-editor"><h3>Column 3 — Discover</h3>
                <label class="header-field">Column title<input class="field" name="footer_discover_title" id="footer-discover-title" maxlength="60" value="<?=e($footerSettings['discover_title'])?>"></label>
                <div class="footer-link-editor" id="footer-discover-editor"><?php foreach($footerSettings['discover_links'] as $link): ?><div class="footer-link-row"><input class="field" name="discover_label[]" maxlength="100" aria-label="Discover link title" placeholder="Link title" value="<?=e($link['label'])?>"><input class="field" name="discover_url[]" maxlength="500" aria-label="Discover link URL" placeholder="Page URL" value="<?=e($link['url'])?>"><button type="button" class="btn light remove-footer-link">Remove</button></div><?php endforeach; ?></div>
                <button type="button" class="btn light add-footer-link" data-group="discover">+ Add Discover Link</button>
              </div>
            </div>
          </div>
          <div class="header-settings-actions"><button type="submit" class="btn">Save Footer Settings</button></div>
        </form>
      </section>
      <section class="page-management-tabpanel" id="pm-panel-contents" role="tabpanel" aria-labelledby="pm-tab-contents" hidden>
        <?php require __DIR__ . '/../app/content-manager-view.php'; ?>
      </section>
      
<?php if($message==='Sections settings successfully saved.'): ?><div class="panel" role="status" style="border-color:#3c8b65;color:#c8f2d9;margin-top:14px">✓ Sections settings successfully saved.</div><?php endif; ?>
      <section class="page-management-tabpanel" id="pm-panel-sections" role="tabpanel" aria-labelledby="pm-tab-sections" hidden>
 <style>
 .section-builder-tabs{--tab-index:0;position:relative;isolation:isolate;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:4px;width:min(100%,650px);margin:18px 0 22px;padding:5px;border:1px solid #45313a;border-radius:999px;background:linear-gradient(135deg,#100c0f,#1a1117);box-shadow:inset 0 2px 8px #0009,0 8px 22px #0003;overflow:hidden}
 .section-tab-indicator{position:absolute;z-index:-1;left:5px;top:5px;bottom:5px;width:calc((100% - 10px - 8px)/3);border:1px solid #ef3340;border-radius:999px;background:linear-gradient(135deg,#751624,#3c0b14);box-shadow:0 4px 14px #ef334033,inset 0 1px #ffffff24;transform:translateX(calc(var(--tab-index)* (100% + 4px)));transition:transform .38s cubic-bezier(.2,.8,.2,1),background .25s,border-color .25s,box-shadow .25s}
 .section-builder-tabs[data-active=slideshow] .section-tab-indicator{background:linear-gradient(135deg,#164d83,#102849);border-color:#20b9ff;box-shadow:0 4px 14px #20b9ff30,inset 0 1px #ffffff24}
 .section-builder-tabs[data-active=mega_menu] .section-tab-indicator{background:linear-gradient(135deg,#796800,#403700);border-color:#f5dc00;box-shadow:0 4px 14px #f5dc0030,inset 0 1px #ffffff24}
 .section-builder-tabs button{position:relative;z-index:1;min-width:0;border:1px solid transparent;background:transparent;color:#cbbdc3;border-radius:999px;padding:11px 14px;font:700 13px Ubuntu;letter-spacing:.02em;cursor:pointer;transition:color .2s,transform .2s}
 .section-builder-tabs button:hover{color:#fff}
 .section-builder-tabs button[aria-selected=true]{color:#fff;text-shadow:0 1px 8px #0008}
 .section-builder-tabs button:focus-visible{outline:2px solid #fff;outline-offset:2px}
 @media(max-width:520px){.section-builder-tabs{width:100%;gap:2px}.section-builder-tabs button{padding:10px 5px;font-size:11px}.section-tab-indicator{width:calc((100% - 10px - 4px)/3);transform:translateX(calc(var(--tab-index)*(100% + 2px)))}}
 .section-subpanel[hidden]{display:none}.section-preview{border:1px solid #55414a;background:#0b090b;border-radius:13px;padding:15px;margin-bottom:20px;overflow:hidden}
 .section-preview h3{font:28px Georgia,serif;margin:5px 0 9px}.section-preview .preview-kicker{font:700 9px Ubuntu;letter-spacing:.18em;color:#ff5268}
 .section-preview .preview-desc{font:12px Ubuntu;color:#d8cbd0}.section-preview-media{display:flex;gap:10px;overflow:auto;margin-top:10px}.section-preview-media img{width:180px;height:105px;object-fit:cover;border-radius:8px}
 .section-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.section-field{display:grid;gap:7px;font:600 12px Ubuntu;color:#f2e6e9}.section-field input,.section-field textarea,.section-field select{width:100%;box-sizing:border-box;background:#171115;border:1px solid var(--line);border-radius:8px;padding:10px;color:#f7edf0;font:13px Ubuntu}.section-field textarea{min-height:78px}
 .banner-editor-grid{grid-template-columns:repeat(4,minmax(0,1fr));align-items:stretch;gap:12px}
 .banner-editor-field{grid-column:span 2!important;min-width:0;align-content:start}
 .banner-editor-field:nth-child(3){grid-column:span 3!important}
 .banner-editor-field:nth-child(4){grid-column:span 1!important}
 .banner-rich-toolbar{display:flex;flex-wrap:wrap;align-items:center;gap:5px;padding:9px;border:1px solid rgba(190,126,145,.22);border-bottom:0;border-radius:8px 8px 0 0;background:linear-gradient(135deg,rgba(105,34,54,.25),rgba(22,15,19,.95))}
 .banner-rich-toolbar button,.banner-rich-toolbar select,.banner-rich-toolbar input[type=color]{height:30px;border:1px solid rgba(190,126,145,.28);border-radius:6px;background:#24171e;color:#f4e7eb;font:11px Ubuntu,sans-serif}
 .banner-rich-toolbar button{min-width:30px;padding:0 8px;cursor:pointer}.banner-rich-toolbar button:hover{background:#542637;border-color:#a34b65}
 .banner-rich-toolbar select{padding:0 7px;max-width:140px}.banner-rich-toolbar input[type=color]{width:34px;padding:3px;cursor:pointer}
 .banner-rich-editor{width:100%;min-width:0;min-height:116px;max-height:190px;overflow:auto;box-sizing:border-box;padding:10px 11px;border:1px solid var(--line);border-radius:0 0 8px 8px;background:#171115;color:#f7edf0;font:13px/1.55 Ubuntu,sans-serif;outline:none;overflow-wrap:anywhere}
 .banner-rich-editor img{max-width:100%;height:auto;border-radius:6px}.banner-rich-editor figure{margin:12px auto;text-align:center}.banner-rich-editor figcaption{color:#bba8b0;font-size:11px}
 .banner-rich-editor:focus{border-color:#b94a61;box-shadow:0 0 0 3px rgba(185,74,97,.16)}
 .banner-rich-editor:empty:before{content:attr(data-placeholder);color:#8f7c83;pointer-events:none}
 .banner-photo-field{min-height:100%;padding:0;align-content:start}
 .banner-photo-field input[type=file]{min-height:116px;padding:12px;border-style:dashed;display:flex;align-items:center}
 .banner-photo-field small{color:#bdaab1;font-size:10px;line-height:1.45;overflow-wrap:anywhere}
 @media(max-width:760px){.banner-editor-grid{grid-template-columns:1fr}.banner-editor-field,.banner-editor-field:nth-child(3),.banner-editor-field:nth-child(4){grid-column:1/-1!important}}

 .section-editor{min-height:110px;border:1px solid var(--line);border-radius:8px;background:#120d10;padding:10px;color:#f5e9ed;font:13px Ubuntu}.section-toolbar{display:flex;gap:5px;flex-wrap:wrap;margin:5px 0}.section-toolbar button{background:#2b1a21;border:1px solid #5b3a46;color:#fff;border-radius:5px;padding:5px 9px;cursor:pointer}
 .section-repeat-card{border:1px solid #46323a;background:#100c0f;border-radius:12px;padding:14px;margin:14px 0}.section-repeat-head{display:flex;justify-content:space-between;align-items:center;gap:8px}.section-remove{border:1px solid #9e3144;color:#ff9aaa;background:#281017;border-radius:7px;padding:6px 10px;cursor:pointer}

 .banner-preview-stage{padding:0;border-radius:13px;background:#101511;overflow:hidden}
 .banner-preview-grid{display:grid;grid-template-columns:1.08fr .92fr;gap:clamp(18px,4vw,58px);align-items:center;position:relative;padding:clamp(24px,5vw,58px);min-height:340px;box-sizing:border-box}
 .banner-preview-copy{min-width:0}
 .banner-preview-stage .preview-kicker{font:850 10px/1.5 Ubuntu,sans-serif;letter-spacing:2.5px;text-transform:uppercase;color:#d8b477}
 .banner-preview-stage h3{font:500 clamp(30px,4.6vw,56px)/.99 Georgia,'Times New Roman',serif;letter-spacing:-1.8px;margin:19px 0 23px;max-width:720px;color:#f3eee7}
 .banner-preview-stage .preview-desc{font:13px/1.7 Ubuntu,sans-serif;color:#b0bcb4;max-width:560px}
 .banner-preview-stage .preview-desc p{margin:0 0 10px}
 .banner-preview-actions{margin-top:22px}
 .banner-preview-actions span{display:inline-flex;align-items:center;border:1px solid #d8b477;border-radius:999px;background:#d8b477;color:#101611;padding:12px 19px;font:750 12px Ubuntu,sans-serif}
 .banner-preview-note{display:flex;align-items:center;gap:8px;margin-top:18px;color:#a8b2aa;font:10px/1.5 Ubuntu,sans-serif}
 .banner-preview-note i{width:7px;height:7px;flex:0 0 7px;border-radius:50%;background:#d8b477}
 .banner-preview-art{min-height:300px;height:100%;align-self:stretch;position:relative;overflow:hidden;display:flex;align-items:flex-end;padding:22px;box-sizing:border-box;border:1px solid rgba(184,50,63,.28);border-radius:22px;background:radial-gradient(ellipse at 50% 45%,rgba(130,27,48,.36),transparent 52%),linear-gradient(145deg,#2a090f,#0d0d0f 72%);box-shadow:0 14px 36px rgba(0,0,0,.3)}
 .banner-preview-art:before{content:'';position:absolute;width:66%;height:76%;right:18%;top:9%;border-radius:48% 48% 13% 13%;transform:rotate(9deg);background:linear-gradient(145deg,#c72c3b,#6f101f 55%,#210b11);box-shadow:-100px 80px 0 -34px #4b101b,92px 115px 0 -28px #8b1728;opacity:.55;filter:saturate(.8)}
 .banner-preview-art img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;z-index:0}
 .banner-preview-stamp{position:absolute;z-index:1;right:18px;top:18px;padding:9px 11px;border:1px solid rgba(255,255,255,.4);border-radius:8px;color:#fff;text-align:center;font:800 10px/1.3 Ubuntu,sans-serif;letter-spacing:1.4px;background:rgba(12,8,10,.35)}
 .banner-preview-stamp span{font-size:8px;letter-spacing:2px}
 @media(max-width:760px){.banner-preview-grid{grid-template-columns:1fr;gap:22px;padding:24px}.banner-preview-art{min-height:240px}.banner-preview-stage h3{font-size:clamp(32px,9vw,48px)}}
 @media(max-width:760px){.section-form-grid{grid-template-columns:1fr}}
 </style>
 
 <nav class="section-builder-tabs" data-active="banner" aria-label="Homepage section editor">
  <span class="section-tab-indicator" aria-hidden="true"></span>
  <button type="button" data-section-tab="banner" aria-selected="true" role="tab">Banner</button><button type="button" data-section-tab="slideshow" aria-selected="false" role="tab">Slideshow</button><button type="button" data-section-tab="mega_menu" aria-selected="false" role="tab">Mega Menu</button>
 </nav>
 <form method="post" enctype="multipart/form-data" id="sections-settings-form"><input type="hidden" name="csrf" value="<?=e(csrf_token())?>"><input type="hidden" name="action" value="save_sections_settings"><input type="hidden" name="section_editor_tab" id="section-editor-tab" value="banner">
 <section class="section-subpanel" data-section-panel="banner">
  <div class="section-preview banner-preview-stage" id="banner-preview">
   <div class="banner-preview-grid">
    <div class="banner-preview-copy">
     <div class="preview-kicker" id="banner-preview-title"><?=$sectionsSettings['banner']['main_title']?></div>
     <h3 id="banner-preview-heading"><?=$sectionsSettings['banner']['heading']?></h3>
     <div class="preview-desc" id="banner-preview-description"><?=$sectionsSettings['banner']['description']?></div>
    </div>
    <div class="banner-preview-art">
     <img id="banner-preview-photo" src="<?=e(!empty($sectionsSettings['banner']['photo'])?ltrim((string)$sectionsSettings['banner']['photo'],'/'):'assets/images/Sunset%20Vinta%20by%20the%20Historic%20Fort.png')?>" alt="Banner photo preview">
    </div>
   </div>
  </div>
  <div class="section-form-grid banner-editor-grid">
   <?php foreach(['banner_main_title'=>'Main Title','banner_heading'=>'Heading','banner_description'=>'Description'] as $editorKey=>$editorLabel): $storageKey=$editorKey==='banner_main_title'?'main_title':($editorKey==='banner_heading'?'heading':'description'); $editorValue=(string)($sectionsSettings['banner'][$storageKey]??''); ?>
   <label class="section-field banner-editor-field"><?=e($editorLabel)?>
    <div class="banner-rich-toolbar content-manager-rich-toolbar" role="toolbar" aria-label="<?=e($editorLabel)?> formatting">
     <select data-banner-block aria-label="Text style"><option value="P">Paragraph</option><option value="H2">Heading 2</option><option value="H3">Heading 3</option><option value="H4">Heading 4</option><option value="BLOCKQUOTE">Quote</option></select>
     <select data-banner-font-family aria-label="Font family"><option value="Ubuntu">Ubuntu</option><option value="Arial">Arial</option><option value="Georgia">Georgia</option><option value="Verdana">Verdana</option><option value="Tahoma">Tahoma</option><option value="Times New Roman">Times New Roman</option><option value="Trebuchet MS">Trebuchet MS</option></select>
     <select data-banner-font-size aria-label="Font size"><option value="">Font size</option><option value="8">8px</option><option value="12">12px</option><option value="16">16px</option><option value="20">20px</option><option value="24">24px</option><option value="28">28px</option><option value="32">32px</option><option value="36">36px</option><option value="40">40px</option><option value="44">44px</option><option value="48">48px</option><option value="52">52px</option><option value="56">56px</option><option value="60">60px</option><option value="64">64px</option><option value="68">68px</option><option value="72">72px</option></select>
     <select data-banner-font-weight aria-label="Font weight"><option value="">Weight</option><option value="400">Normal</option><option value="500">Medium</option><option value="600">Semi-bold</option><option value="700">Bold</option><option value="800">Extra bold</option></select>
     <span class="content-manager-rich-divider"></span>
     <button type="button" data-banner-command="bold" title="Bold"><strong>B</strong></button>
     <button type="button" data-banner-command="italic" title="Italic"><em>I</em></button>
     <button type="button" data-banner-command="underline" title="Underline"><u>U</u></button>
     <button type="button" data-banner-command="strikeThrough" title="Strikethrough"><s>S</s></button>
     <button type="button" data-banner-command="removeFormat" title="Clear formatting">Tx</button>
     <span class="content-manager-rich-divider"></span>
     <button type="button" data-banner-command="justifyLeft" title="Align left">≡</button>
     <button type="button" data-banner-command="justifyCenter" title="Align center">☰</button>
     <button type="button" data-banner-command="justifyRight" title="Align right">≡</button>
     <button type="button" data-banner-command="insertUnorderedList" title="Bulleted list">• List</button>
     <button type="button" data-banner-command="insertOrderedList" title="Numbered list">1. List</button>
     <button type="button" data-banner-action="link" title="Insert link">Link</button>
     <button type="button" data-banner-action="unlink" title="Remove link">Unlink</button>
     <button type="button" data-banner-action="quote" title="Quote">❝</button>
     <button type="button" data-banner-action="table" title="Insert table">Table</button>
     <span class="content-manager-rich-divider"></span>
     <button type="button" data-banner-action="image" title="Upload photo">Photo</button>
     <label title="Text color" style="display:flex;align-items:center;gap:4px;color:#d9cbd0;font-size:10px">Color <input type="color" data-banner-color value="#f4e9ed" aria-label="Text color"></label>
     <button type="button" data-banner-command="undo" title="Undo">↶</button>
     <button type="button" data-banner-command="redo" title="Redo">↷</button>
     <input type="file" data-banner-image-input accept="image/jpeg,image/png,image/gif,image/webp" hidden>
    </div>
    <div class="banner-rich-editor" contenteditable="true" role="textbox" aria-multiline="true" data-banner-text="<?=e($editorKey)?>" data-placeholder="Enter <?=e(strtolower($editorLabel))?>..."><?=$editorValue?></div>
    <input type="hidden" name="<?=e($editorKey)?>" data-banner-html="<?=e($editorKey)?>" value="<?=e($editorValue)?>">
   </label>
   <?php endforeach; ?>
   <label class="section-field banner-editor-field banner-photo-field">Upload / Replace Banner Photo
    <input type="file" name="banner_photo" accept="image/png,image/jpeg,image/webp,image/gif">
    <?php if(!empty($sectionsSettings['banner']['photo'])): ?><small>Current photo: <?=e(basename($sectionsSettings['banner']['photo']))?></small><?php else: ?><small>Choose a JPG, PNG, GIF or WebP image (max. 10 MB).</small><?php endif; ?>
   </label>
  </div>
 </section>
 <section class="section-subpanel" data-section-panel="slideshow" hidden>
  <div class="section-preview"><div class="preview-kicker" id="slide-preview-title"><?=e($sectionsSettings['slideshow']['main_title'])?></div><h3 id="slide-preview-heading"><?=e($sectionsSettings['slideshow']['heading'])?></h3><p class="preview-desc" id="slide-preview-subtitle"><?=e($sectionsSettings['slideshow']['subtitle'])?></p><div class="section-preview-media" id="slide-preview-media"><?php foreach($sectionsSettings['slideshow']['photos'] as $ph): if(!empty($ph['photo'])): ?><img src="<?=e($ph['photo'])?>" alt=""><?php endif; endforeach; ?></div></div>
  <div class="section-form-grid"><label class="section-field">Main Title<input name="slide_main_title" value="<?=e($sectionsSettings['slideshow']['main_title'])?>" data-preview="slide-preview-title"></label><label class="section-field">Heading<input name="slide_heading" value="<?=e($sectionsSettings['slideshow']['heading'])?>" data-preview="slide-preview-heading"></label><label class="section-field" style="grid-column:1/-1">Subtitle<input name="slide_subtitle" value="<?=e($sectionsSettings['slideshow']['subtitle'])?>" data-preview="slide-preview-subtitle"></label></div>
  <div class="section-repeat-head"><h3>Slideshow Photos</h3><button type="button" class="btn light" id="add-slide-photo">+ Add Photo</button></div><div id="slide-photo-list">
  <?php foreach($sectionsSettings['slideshow']['photos'] as $k=>$ph): ?><div class="section-repeat-card"><div class="section-repeat-head"><strong>Photo <?=($k+1)?></strong><button type="button" class="section-remove">Remove</button></div><div class="section-form-grid"><label class="section-field">Photo Title<input name="slide_title[]" value="<?=e($ph['title']??'')?>"></label><label class="section-field">Photo Heading<input name="slide_photo_heading[]" value="<?=e($ph['heading']??'')?>"></label><label class="section-field" style="grid-column:1/-1">Description<div class="section-toolbar"><button type="button" data-cmd="bold">B</button><button type="button" data-cmd="italic">I</button><button type="button" data-cmd="underline">U</button><button type="button" data-link>Link</button></div><div class="section-editor" contenteditable="true"><?=$ph['description']??''?></div><textarea hidden name="slide_description[]"><?=e($ph['description']??'')?></textarea></label><label class="section-field">Upload Photo<input type="file" name="slide_photo[]" accept="image/*"><?php if(!empty($ph['photo'])): ?><small>Current photo: <?=e(basename($ph['photo']))?></small><?php endif; ?></label></div></div><?php endforeach; ?>
  </div>
 </section>
 <section class="section-subpanel" data-section-panel="mega_menu" hidden>
  <div class="section-preview"><div class="preview-kicker" id="mega-preview-title"><?=e($sectionsSettings['mega_menu']['main_title'])?></div><h3 id="mega-preview-heading"><?=e($sectionsSettings['mega_menu']['heading'])?></h3><p class="preview-desc" id="mega-preview-subtitle"><?=e($sectionsSettings['mega_menu']['subtitle'])?></p><div class="section-preview-media"><?php foreach($sectionsSettings['mega_menu']['panels'] as $pn): if(!empty($pn['photo'])): ?><img src="<?=e($pn['photo'])?>" alt=""><?php endif; endforeach; ?></div></div>
  <div class="section-form-grid"><label class="section-field">Main Title<input name="mega_main_title" value="<?=e($sectionsSettings['mega_menu']['main_title'])?>" data-preview="mega-preview-title"></label><label class="section-field">Heading<input name="mega_heading" value="<?=e($sectionsSettings['mega_menu']['heading'])?>" data-preview="mega-preview-heading"></label><label class="section-field" style="grid-column:1/-1">Subtitle<input name="mega_subtitle" value="<?=e($sectionsSettings['mega_menu']['subtitle'])?>" data-preview="mega-preview-subtitle"></label></div>
  <div class="section-repeat-head"><h3>Panel View Menu</h3><button type="button" class="btn light" id="add-mega-panel">+ Add Panel</button></div><div id="mega-panel-list"><?php foreach($sectionsSettings['mega_menu']['panels'] as $pn): ?><div class="section-repeat-card"><div class="section-repeat-head"><strong>Menu Panel</strong><button type="button" class="section-remove">Remove</button></div><div class="section-form-grid"><label class="section-field">Panel Title<input name="panel_title[]" value="<?=e($pn['title']??'')?>"></label><label class="section-field">Panel Description<textarea name="panel_description[]"><?=e($pn['description']??'')?></textarea></label><label class="section-field">Panel Page URL<input name="panel_url[]" value="<?=e($pn['url']??'')?>"></label><label class="section-field">Upload Panel Cover Photo<input type="file" name="panel_photo[]" accept="image/*"><?php if(!empty($pn['photo'])): ?><small>Current photo: <?=e(basename($pn['photo']))?></small><?php endif; ?></label></div></div><?php endforeach; ?></div>
 </section>
 <div class="header-settings-actions"><button type="submit" class="btn" id="save-section-settings-button">Save Banner Settings</button></div>
 </form>
 <script>
 (()=>{const root=document.getElementById('pm-panel-sections');if(!root)return;const tabs=[...root.querySelectorAll('[data-section-tab]')],panels=[...root.querySelectorAll('[data-section-panel]')],tabNav=root.querySelector('.section-builder-tabs');const saveTabField=document.getElementById('section-editor-tab'),saveTabButton=document.getElementById('save-section-settings-button');const activateSectionTab=(t)=>{const index=tabs.indexOf(t);tabNav.dataset.active=t.dataset.sectionTab;tabNav.style.setProperty('--tab-index',String(index));if(saveTabField)saveTabField.value=t.dataset.sectionTab;if(saveTabButton)saveTabButton.textContent=t.dataset.sectionTab==='banner'?'Save Banner Settings':'Save Section Settings';tabs.forEach(x=>{const on=x===t;x.setAttribute('aria-selected',String(on));x.tabIndex=on?0:-1;});panels.forEach(p=>{p.hidden=p.dataset.sectionPanel!==t.dataset.sectionTab;});};tabs.forEach((t,index)=>{t.addEventListener('click',()=>activateSectionTab(t));t.addEventListener('keydown',e=>{if(e.key!=='ArrowLeft'&&e.key!=='ArrowRight')return;e.preventDefault();const next=tabs[(index+(e.key==='ArrowRight'?1:-1)+tabs.length)%tabs.length];activateSectionTab(next);next.focus();});});activateSectionTab(tabs.find(t=>t.getAttribute('aria-selected')==='true')||tabs[0]);
 root.querySelectorAll('[data-preview]').forEach(i=>i.addEventListener('input',()=>{const el=document.getElementById(i.dataset.preview);if(el)el.textContent=i.value}));
 const bannerPreviewIds={banner_main_title:'banner-preview-title',banner_heading:'banner-preview-heading',banner_description:'banner-preview-description'};
 const cleanBannerPreviewHtml=html=>{
   const parsed=new DOMParser().parseFromString('<div>'+html+'</div>','text/html');
   const rootNode=parsed.body.firstElementChild;
   const allowed=new Set(['P','BR','STRONG','B','EM','I','U','S','UL','OL','LI','H2','H3','H4','BLOCKQUOTE','A','SPAN','FIGURE','IMG','FIGCAPTION','TABLE','TBODY','TR','TH','TD']);
   const walk=node=>{
     [...node.childNodes].forEach(child=>{
       if(child.nodeType===Node.ELEMENT_NODE){
         if(!allowed.has(child.tagName)){child.replaceWith(...child.childNodes);return;}
         [...child.attributes].forEach(attr=>{
           if(attr.name.toLowerCase()==='style'){
             const declarations=[];
             attr.value.split(';').forEach(rule=>{
               const parts=rule.split(':');if(parts.length<2)return;
               const key=parts.shift().trim().toLowerCase(),value=parts.join(':').trim();
               if(key==='color'&&/^(#[0-9a-f]{3,8}|rgba?\([0-9.,% ]+\)|[a-z]{3,20})$/i.test(value))declarations.push('color:'+value);
               else if(key==='font-size'&&/^(?:[8-9]|[1-6][0-9]|7[0-2])(?:\.\d+)?px$/i.test(value))declarations.push('font-size:'+value);
               else if(key==='font-family'&&/^[a-z0-9 ,'-]{1,80}$/i.test(value))declarations.push('font-family:'+value);
               else if(key==='font-weight'&&/^(normal|bold|400|500|600|700|800|900)$/i.test(value))declarations.push('font-weight:'+value);
               else if(key==='font-style'&&/^(normal|italic|oblique)$/i.test(value))declarations.push('font-style:'+value);
               else if(key==='text-align'&&/^(left|right|center|justify)$/i.test(value))declarations.push('text-align:'+value);
               else if(key==='text-decoration'&&/^(none|underline|line-through|overline)(\s+(underline|line-through|overline))*$/i.test(value))declarations.push('text-decoration:'+value);
               else if(key==='line-height'&&/^(?:[0-9]+(?:\.\d+)?|[0-9]+(?:\.\d+)?px|[0-9]+(?:\.\d+)?%)$/i.test(value))declarations.push('line-height:'+value);
               else if(key==='letter-spacing'&&/^-?[0-9]+(?:\.\d+)?px$/i.test(value))declarations.push('letter-spacing:'+value);
             });
             if(declarations.length)child.setAttribute('style',declarations.join(';'));else child.removeAttribute('style');
           }else if(child.tagName==='A'&&attr.name.toLowerCase()==='href'&&/^(https?:\/\/|\/|#|\?|mailto:)/i.test(attr.value)){}
           else child.removeAttribute(attr.name);
         });
         if(child.tagName==='A'){child.setAttribute('target','_blank');child.setAttribute('rel','noopener');}
         if(child.tagName==='IMG'){
           const src=child.getAttribute('src')||'';
           if(!/^(?:\/)?uploads\/articles\//i.test(src)){child.remove();return;}
           [...child.attributes].forEach(attr=>{if(!['src','alt'].includes(attr.name.toLowerCase()))child.removeAttribute(attr.name);});
         }
         walk(child);
       }else if(child.nodeType!==Node.TEXT_NODE){child.remove();}
     });
   };
   walk(rootNode);return rootNode.innerHTML;
 };
 const refreshBannerPreview=key=>{
   const field=root.querySelector('[data-banner-text="'+key+'"]');
   const hidden=root.querySelector('[data-banner-html="'+key+'"]');
   const preview=document.getElementById(bannerPreviewIds[key]);
   if(!field||!hidden||!preview)return;
   const html=cleanBannerPreviewHtml(field.innerHTML);
   hidden.value=html;
   preview.innerHTML=html;
 };
 const bannerEditors=[...root.querySelectorAll('[data-banner-text]')];
 const bannerSelection=new Map();
 // Keep an editor-specific HTML history because Range-based formatting and DOM insertion
 // do not reliably enter the browser's native contenteditable undo stack.
 const bannerHistory=new Map();
 const bannerHistoryTimers=new Map();
 const commitBannerHistory=field=>{
   const key=field.dataset.bannerText;
   const history=bannerHistory.get(key);
   if(!history)return;
   const timer=bannerHistoryTimers.get(key);
   if(timer){clearTimeout(timer);bannerHistoryTimers.delete(key);}
   const html=field.innerHTML;
   if(history.entries[history.index]===html)return;
   history.entries=history.entries.slice(0,history.index+1);
   history.entries.push(html);
   history.index=history.entries.length-1;
 };
 const scheduleBannerHistory=field=>{
   const key=field.dataset.bannerText;
   const oldTimer=bannerHistoryTimers.get(key);
   if(oldTimer)clearTimeout(oldTimer);
   bannerHistoryTimers.set(key,setTimeout(()=>commitBannerHistory(field),350));
 };
 const moveBannerHistory=(field,direction)=>{
   const key=field.dataset.bannerText;
   commitBannerHistory(field);
   const history=bannerHistory.get(key);
   if(!history)return;
   const next=history.index+direction;
   if(next<0||next>=history.entries.length)return;
   history.index=next;
   field.innerHTML=history.entries[history.index];
   bannerSelection.delete(key);
   field.focus({preventScroll:true});
   refreshBannerPreview(key);
 };
 const rememberBannerSelection=field=>{
   const selection=window.getSelection();
   if(selection&&selection.rangeCount&&!selection.isCollapsed&&field.contains(selection.anchorNode)&&field.contains(selection.focusNode)){
     bannerSelection.set(field.dataset.bannerText,selection.getRangeAt(0).cloneRange());
   }
 };
 const restoreBannerSelection=field=>{
   if(!field)return null;
   const range=bannerSelection.get(field.dataset.bannerText);
   field.focus({preventScroll:true});
   if(range&&field.contains(range.commonAncestorContainer)){
     const selection=window.getSelection();
     selection.removeAllRanges();
     selection.addRange(range.cloneRange());
     return selection.getRangeAt(0);
   }
   return null;
 };
 document.addEventListener('selectionchange',()=>{
   const selection=window.getSelection();
   const active=bannerEditors.find(field=>selection&&field.contains(selection.anchorNode)&&field.contains(selection.focusNode));
   if(active)rememberBannerSelection(active);
 });
 root.querySelectorAll('.banner-rich-toolbar').forEach(toolbar=>{
   toolbar.addEventListener('pointerdown',event=>{
     const field=toolbar.closest('.banner-editor-field')?.querySelector('[data-banner-text]');
     if(field)rememberBannerSelection(field);
     // Keep the browser's selection in the editable surface when toolbar buttons are pressed.
     if(event.target.closest('button'))event.preventDefault();
   },true);
   toolbar.addEventListener('mousedown',event=>{
     if(event.target.closest('button'))event.preventDefault();
   },true);
 });
 bannerEditors.forEach(field=>{
   const key=field.dataset.bannerText;
   const hidden=root.querySelector('[data-banner-html="'+key+'"]');
   if(hidden&&hidden.value)field.innerHTML=hidden.value;
   bannerHistory.set(key,{entries:[field.innerHTML],index:0});
   field.addEventListener('input',()=>{refreshBannerPreview(key);scheduleBannerHistory(field);});
   ['keyup','mouseup','touchend'].forEach(name=>field.addEventListener(name,()=>rememberBannerSelection(field)));
   field.addEventListener('blur',()=>refreshBannerPreview(key));
   refreshBannerPreview(key);
 });
 const bannerCsrf=root.querySelector('input[name="csrf"]')?.value||'';
 const placeBannerFragment=(field,fragment)=>{
   const range=restoreBannerSelection(field)||document.createRange();
   if(!bannerSelection.has(field.dataset.bannerText)){
     range.selectNodeContents(field);
     range.collapse(false);
   }
   range.deleteContents();
   const last=fragment.lastChild;
   range.insertNode(fragment);
   if(last){
     const selection=window.getSelection(),caret=document.createRange();
     caret.setStartAfter(last);caret.collapse(true);selection.removeAllRanges();selection.addRange(caret);
   }
   field.dispatchEvent(new Event('input',{bubbles:true}));
   const selection=window.getSelection();
   if(selection&&selection.rangeCount&&!selection.isCollapsed)rememberBannerSelection(field);
   else bannerSelection.delete(field.dataset.bannerText);
 };
 const wrapBannerSelection=(field,tag,styles={})=>{
   const range=restoreBannerSelection(field);
   if(!range||range.collapsed)return false;
   const wrapper=document.createElement(tag);
   Object.entries(styles).forEach(([property,value])=>wrapper.style[property]=value);
   try{range.surroundContents(wrapper);}
   catch(error){wrapper.append(range.extractContents());range.insertNode(wrapper);}
   const selection=window.getSelection(),next=document.createRange();
   next.selectNodeContents(wrapper);selection.removeAllRanges();selection.addRange(next);
   rememberBannerSelection(field);
   field.dispatchEvent(new Event('input',{bubbles:true}));
   return true;
 };
 const bannerRunCommand=(field,command,value=null)=>{
   if(!field)return;
   const range=restoreBannerSelection(field);
   if(['bold','italic','underline','strikeThrough'].includes(command)){
     const tags={bold:'strong',italic:'em',underline:'u',strikeThrough:'s'};
     if(range&&!range.collapsed)wrapBannerSelection(field,tags[command]);
     return;
   }
   if(command==='foreColor'){wrapBannerSelection(field,'span',{color:value});return;}
   if(command==='formatBlock'){
     if(!range)return;
     const tag=String(value||'P').replace(/[<>]/g,'').toLowerCase();
     if(!/^(p|h2|h3|h4|blockquote)$/.test(tag))return;
     const block=document.createElement(tag);
     if(range.collapsed){block.innerHTML='<br>';range.insertNode(block);}
     else{try{range.surroundContents(block);}catch(error){block.append(range.extractContents());range.insertNode(block);}}
     field.dispatchEvent(new Event('input',{bubbles:true}));
     const selection=window.getSelection(),next=document.createRange();next.selectNodeContents(block);selection.removeAllRanges();selection.addRange(next);rememberBannerSelection(field);return;
   }
   if(['justifyLeft','justifyCenter','justifyRight'].includes(command)){
     if(!range)return;
     let node=range.startContainer.nodeType===Node.ELEMENT_NODE?range.startContainer:range.startContainer.parentElement;
     while(node&&node!==field&&!/^(P|H2|H3|H4|BLOCKQUOTE|LI|DIV)$/.test(node.tagName))node=node.parentElement;
     if(node&&node!==field)node.style.textAlign=command==='justifyLeft'?'left':command==='justifyCenter'?'center':'right';
     else field.style.textAlign=command==='justifyLeft'?'left':command==='justifyCenter'?'center':'right';
     field.dispatchEvent(new Event('input',{bubbles:true}));return;
   }
   if(command==='insertHTML'){
     const fragment=document.createRange().createContextualFragment(String(value||''));
     placeBannerFragment(field,fragment);return;
   }
   if(command==='createLink'){
     if(range&&!range.collapsed){const anchor=document.createElement('a');anchor.href=String(value||'');try{range.surroundContents(anchor);}catch(error){anchor.append(range.extractContents());range.insertNode(anchor);}field.dispatchEvent(new Event('input',{bubbles:true}));rememberBannerSelection(field);}return;
   }
   if(command==='unlink'){
     const selection=window.getSelection(),anchor=selection?.anchorNode?.parentElement?.closest('a');
     if(anchor&&field.contains(anchor)){anchor.replaceWith(...anchor.childNodes);field.dispatchEvent(new Event('input',{bubbles:true}));}return;
   }
   if(command==='insertUnorderedList'||command==='insertOrderedList'){
     if(!range)return;
     const list=document.createElement(command==='insertUnorderedList'?'ul':'ol');
     const item=document.createElement('li');
     if(range.collapsed)item.innerHTML='<br>';else item.append(range.extractContents());
     list.append(item);range.insertNode(list);field.dispatchEvent(new Event('input',{bubbles:true}));
     const selection=window.getSelection(),next=document.createRange();next.selectNodeContents(item);selection.removeAllRanges();selection.addRange(next);rememberBannerSelection(field);return;
   }
   if(command==='removeFormat'){
     if(!range||range.collapsed)return;
     const fragment=range.extractContents();
     fragment.querySelectorAll?.('[style]').forEach(node=>node.removeAttribute('style'));
     fragment.querySelectorAll?.('strong,b,em,i,u,s,span,font').forEach(node=>node.replaceWith(...node.childNodes));
     range.insertNode(fragment);field.dispatchEvent(new Event('input',{bubbles:true}));return;
   }
   // Use the editor's own snapshot history for text and formatting changes.
   if(command==='undo'){moveBannerHistory(field,-1);return;}
   if(command==='redo'){moveBannerHistory(field,1);return;}
   field.dispatchEvent(new Event('input',{bubbles:true}));
 };
 root.querySelectorAll('[data-banner-command]').forEach(button=>{
   button.addEventListener('click',()=>{
     const field=button.closest('.banner-editor-field')?.querySelector('[data-banner-text]');
     if(field)bannerRunCommand(field,button.dataset.bannerCommand);
   });
 });
 root.querySelectorAll('[data-banner-block]').forEach(select=>select.addEventListener('change',()=>{
   const field=select.closest('.banner-editor-field')?.querySelector('[data-banner-text]');
   if(field&&select.value)bannerRunCommand(field,'formatBlock','<'+select.value+'>');
 }));
 const applyBannerInlineStyle=(field,property,value)=>wrapBannerSelection(field,'span',{[property]:value});
 root.querySelectorAll('[data-banner-font-family]').forEach(select=>select.addEventListener('change',()=>{
   const field=select.closest('.banner-editor-field')?.querySelector('[data-banner-text]');if(field)applyBannerInlineStyle(field,'fontFamily',select.value);select.value='';
 }));
 root.querySelectorAll('[data-banner-font-size]').forEach(select=>select.addEventListener('change',()=>{
   const field=select.closest('.banner-editor-field')?.querySelector('[data-banner-text]');if(field)applyBannerInlineStyle(field,'fontSize',select.value+'px');select.value='';
 }));
 root.querySelectorAll('[data-banner-font-weight]').forEach(select=>select.addEventListener('change',()=>{
   const field=select.closest('.banner-editor-field')?.querySelector('[data-banner-text]');if(field)applyBannerInlineStyle(field,'fontWeight',select.value);select.value='';
 }));
 root.querySelectorAll('[data-banner-color]').forEach(input=>input.addEventListener('input',()=>{
   const field=input.closest('.banner-editor-field')?.querySelector('[data-banner-text]');if(field)bannerRunCommand(field,'foreColor',input.value);
 }));
 root.querySelectorAll('[data-banner-action="link"]').forEach(button=>button.addEventListener('click',()=>{
   const field=button.closest('.banner-editor-field')?.querySelector('[data-banner-text]');const url=prompt('Enter the link URL (https://, http://, or mailto:):');
   if(field&&url&&/^(https?:\/\/|mailto:)/i.test(url.trim()))bannerRunCommand(field,'createLink',url.trim());
 }));
 root.querySelectorAll('[data-banner-action="unlink"]').forEach(button=>button.addEventListener('click',()=>{
   const field=button.closest('.banner-editor-field')?.querySelector('[data-banner-text]');if(field)bannerRunCommand(field,'unlink');
 }));
 root.querySelectorAll('[data-banner-action="quote"]').forEach(button=>button.addEventListener('click',()=>{
   const field=button.closest('.banner-editor-field')?.querySelector('[data-banner-text]');if(field)bannerRunCommand(field,'formatBlock','<BLOCKQUOTE>');
 }));
 root.querySelectorAll('[data-banner-action="table"]').forEach(button=>button.addEventListener('click',()=>{
   const field=button.closest('.banner-editor-field')?.querySelector('[data-banner-text]');if(!field)return;
   const rows=Math.min(10,Math.max(1,Number(prompt('Number of table rows?','3')||0))),cols=Math.min(8,Math.max(1,Number(prompt('Number of table columns?','3')||0)));if(!rows||!cols)return;
   let table='<table><tbody>';for(let row=0;row<rows;row++){table+='<tr>';for(let col=0;col<cols;col++)table+=row===0?'<th>Heading</th>':'<td>Text</td>';table+='</tr>';}table+='</tbody></table><p><br></p>';bannerRunCommand(field,'insertHTML',table);
 }));
 const uploadBannerEditorImage=async(field,file)=>{
   if(!file||!file.type.startsWith('image/'))return;if(file.size>8*1024*1024){alert('Please choose an image smaller than 8 MB.');return;}
   const data=new FormData();data.append('csrf',bannerCsrf);data.append('action','upload_article_image');data.append('image',file);
   try{const response=await fetch('admin-content.php?session_context=admin',{method:'POST',body:data,credentials:'same-origin'});const result=await response.json();if(!response.ok||!result.url)throw new Error(result.message||'The image could not be uploaded.');
     const alt=(file.name||'Banner image').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
     bannerRunCommand(field,'insertHTML','<figure><img src="'+result.url+'" alt="'+alt+'"><figcaption>'+alt+'</figcaption></figure><p><br></p>');
   }catch(error){alert(error.message||'Image upload failed.');}
 };
 root.querySelectorAll('[data-banner-action="image"]').forEach(button=>button.addEventListener('click',()=>button.closest('.banner-editor-field')?.querySelector('[data-banner-image-input]')?.click()));
 root.querySelectorAll('[data-banner-image-input]').forEach(input=>input.addEventListener('change',async()=>{const field=input.closest('.banner-editor-field')?.querySelector('[data-banner-text]');if(input.files?.[0]&&field)await uploadBannerEditorImage(field,input.files[0]);input.value='';}));
 const sectionsForm=document.getElementById('sections-settings-form');
 sectionsForm?.addEventListener('submit',()=>{
   root.querySelectorAll('[data-banner-text]').forEach(field=>refreshBannerPreview(field.dataset.bannerText));
 });
 const bannerPhotoInput=root.querySelector('input[name="banner_photo"]');bannerPhotoInput?.addEventListener('change',()=>{const file=bannerPhotoInput.files?.[0],preview=document.getElementById('banner-preview-photo');if(!file||!preview)return;const reader=new FileReader();reader.onload=()=>{preview.src=String(reader.result);};reader.readAsDataURL(file);});
 
 root.querySelectorAll('[data-link]').forEach(b=>b.addEventListener('click',()=>{const url=prompt('Enter link URL');if(url){const ed=b.closest('.section-field')?.querySelector('[contenteditable=true]');ed?.focus();document.execCommand('createLink',false,url);ed?.dispatchEvent(new Event('input'))}}));
 const add=(target,html)=>{document.getElementById(target).insertAdjacentHTML('beforeend',html)};
 document.getElementById('add-slide-photo')?.addEventListener('click',()=>add('slide-photo-list','<div class="section-repeat-card"><div class="section-repeat-head"><strong>New Photo</strong><button type="button" class="section-remove">Remove</button></div><div class="section-form-grid"><label class="section-field">Photo Title<input name="slide_title[]"></label><label class="section-field">Photo Heading<input name="slide_photo_heading[]"></label><label class="section-field" style="grid-column:1/-1">Description<div class="section-editor" contenteditable="true"></div><textarea hidden name="slide_description[]"></textarea></label><label class="section-field">Upload Photo<input type="file" name="slide_photo[]" accept="image/*"></label></div></div>'));
 document.getElementById('add-mega-panel')?.addEventListener('click',()=>add('mega-panel-list','<div class="section-repeat-card"><div class="section-repeat-head"><strong>New Menu Panel</strong><button type="button" class="section-remove">Remove</button></div><div class="section-form-grid"><label class="section-field">Panel Title<input name="panel_title[]"></label><label class="section-field">Panel Description<textarea name="panel_description[]"></textarea></label><label class="section-field">Panel Page URL<input name="panel_url[]"></label><label class="section-field">Upload Panel Cover Photo<input type="file" name="panel_photo[]" accept="image/*"></label></div></div>'));
 root.addEventListener('click',e=>{if(e.target.classList.contains('section-remove'))e.target.closest('.section-repeat-card')?.remove()});
 root.querySelector('#sections-settings-form')?.addEventListener('submit',()=>{root.querySelectorAll('[contenteditable=true]').forEach(ed=>{const ta=ed.parentElement.querySelector('textarea');if(ta)ta.value=ed.innerHTML})});
 })();
 </script>
</section>
    </section>
    <script>
    (() => {
      const form = document.getElementById('footer-settings-form');
      if (!form) return;
      const fields = {
        brand: document.getElementById('footer-brand'),
        description: document.getElementById('footer-description'),
        copyright: document.getElementById('footer-copyright'),
        exploreTitle: document.getElementById('footer-explore-title'),
        discoverTitle: document.getElementById('footer-discover-title')
      };
      const updateLinks = group => {
        const editor = document.getElementById('footer-'+group+'-editor');
        const preview = document.getElementById(group === 'brand' ? 'preview-footer-brand-links' : 'preview-footer-'+group+'-links');
        preview.replaceChildren();
        editor.querySelectorAll('.footer-link-row').forEach(row => {
          const label = row.querySelector('[name="'+group+'_label[]"]').value.trim();
          const url = row.querySelector('[name="'+group+'_url[]"]').value.trim();
          if (!label) return;
          const item = url ? document.createElement('a') : document.createElement('span');
          item.textContent = label;
          if (url) item.href = url; else item.className = 'footer-preview-text-item';
          preview.append(item);
        });
      };
      const updatePreview = () => {
        document.getElementById('preview-footer-brand').childNodes[0].textContent = fields.brand.value;
        document.getElementById('preview-footer-description').textContent = fields.description.value;
        document.getElementById('preview-footer-copyright').textContent = fields.copyright.value.replaceAll('{year}', String(new Date().getFullYear()));
        document.getElementById('preview-footer-explore-title').textContent = fields.exploreTitle.value;
        document.getElementById('preview-footer-discover-title').textContent = fields.discoverTitle.value;
        updateLinks('brand'); updateLinks('explore'); updateLinks('discover');
      };
      const addRow = group => {
        const editor = document.getElementById('footer-'+group+'-editor');
        const row = document.createElement('div'); row.className = 'footer-link-row';
        const label = document.createElement('input'); label.className='field'; label.name=group+'_label[]'; label.maxLength=100; label.placeholder='Link title'; label.setAttribute('aria-label',group+' link title');
        const url = document.createElement('input'); url.className='field'; url.name=group+'_url[]'; url.maxLength=500; url.placeholder='Page URL'; url.setAttribute('aria-label',group+' link URL');
        const remove = document.createElement('button'); remove.type='button'; remove.className='btn light remove-footer-link'; remove.textContent='Remove';
        row.append(label,url,remove); editor.append(row); updatePreview(); label.focus();
      };
      form.addEventListener('input', event => { if (event.target.matches('.footer-link-row input')) event.target.classList.remove('footer-field-invalid'); updatePreview(); });
      form.addEventListener('change', updatePreview);
      let invalidTitleField = null;
      form.addEventListener('submit', event => {
        form.querySelectorAll('.footer-field-invalid').forEach(field => field.classList.remove('footer-field-invalid'));
        for (const group of [{name:'brand',column:'1'},{name:'explore',column:'2'},{name:'discover',column:'3'}]) {
          const rows=[...document.querySelectorAll('#footer-'+group.name+'-editor .footer-link-row')];
          const missing=rows.filter(row=>row.querySelector('[name="'+group.name+'_url[]"]').value.trim()&&!row.querySelector('[name="'+group.name+'_label[]"]').value.trim());
          if(missing.length){event.preventDefault();missing.forEach(row=>row.querySelector('[name="'+group.name+'_label[]"]').classList.add('footer-field-invalid'));invalidTitleField=missing[0].querySelector('[name="'+group.name+'_label[]"]');const popup=document.createElement('div');popup.className='account-status-modal';popup.id='footer-client-validation-modal';popup.setAttribute('role','dialog');popup.setAttribute('aria-modal','true');const card=document.createElement('div');card.className='account-status-modal-card';const icon=document.createElement('div');icon.className='account-status-modal-icon';icon.textContent='!';const heading=document.createElement('h2');heading.textContent='Please enter a title in Column '+group.column+'.';const ok=document.createElement('button');ok.type='button';ok.className='btn';ok.textContent='OK';ok.addEventListener('click',()=>{popup.remove();if(invalidTitleField){invalidTitleField.focus();invalidTitleField.scrollIntoView({behavior:'smooth',block:'center'});}});card.append(icon,heading,ok);popup.append(card);document.body.append(popup);ok.focus();return;}
        }
      });
      form.addEventListener('click', event => {
        const add = event.target.closest('.add-footer-link');
        if (add) { addRow(add.dataset.group); return; }
        const remove = event.target.closest('.remove-footer-link');
        if (remove) { remove.closest('.footer-link-row').remove(); updatePreview(); }
      });
      updatePreview();
    })();
    (() => {
      const form = document.getElementById('header-settings-form');
      if (form) {
        const links = document.getElementById('header-links-list');
        const add = document.getElementById('add-header-link');
        const makeLinkRow = (label = '', url = '') => {
          const row=document.createElement('div');row.className='header-link-row';
          const urlInput=document.createElement('input');urlInput.className='field';urlInput.type='text';urlInput.name='header_link_url[]';urlInput.maxLength=500;urlInput.placeholder='Page URL';urlInput.value=url;
          const labelInput=document.createElement('input');labelInput.className='field';labelInput.type='text';labelInput.name='header_link_label[]';labelInput.maxLength=60;labelInput.placeholder='Link title';labelInput.setAttribute('aria-label','Link title');labelInput.value=label;
          const remove=document.createElement('button');remove.type='button';remove.className='btn light remove-header-link';remove.textContent='Remove';remove.addEventListener('click',()=>{row.remove();updatePreview();});
          row.append(urlInput,labelInput,remove);return row;
        };
        const editorText = row => row.querySelector('[name="header_link_label[]"]')?.value || '';
        const updatePreview = () => {
          const titleEditor=form.querySelector('[data-rich-editor="title"]'),leftEditor=form.querySelector('[data-rich-editor="hidden_left"]'),rightEditor=form.querySelector('[data-rich-editor="hidden_right"]');
          if(document.activeElement!==titleEditor && titleEditor.value.trim()!==form.elements.header_title.value.trim())titleEditor.value=form.elements.header_title.value;
          if(document.activeElement!==leftEditor && leftEditor.innerText.trim()!==form.elements.hidden_left.value.trim())leftEditor.textContent=form.elements.hidden_left.value;
          if(document.activeElement!==rightEditor && rightEditor.innerText.trim()!==form.elements.hidden_right.value.trim())rightEditor.textContent=form.elements.hidden_right.value;
          const title=titleEditor.value.trim() || 'Header Title';form.elements.header_title.value=titleEditor.value.trim();
          document.getElementById('preview-header-title').textContent=title;
          document.getElementById('preview-hidden-left').textContent=form.elements.hidden_left.value;
          document.getElementById('preview-hidden-right').textContent=form.elements.hidden_right.value;
          const brand=document.getElementById('preview-header-brand');
          const savedTypography=<?=json_encode(json_decode((string)($headerSettings['typography_json'] ?? '{}'),true) ?: [],JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>;
          const styleFor=(key)=>{const group=form.querySelectorAll('[name^="typography['+key+']"]');const values={};group.forEach(el=>values[el.name.match(/\[([^\]]+)\]$/)[1]]=el.value);return values;};
          const previewTypography=(key,fallback)=>{const st=styleFor(key);const saved=savedTypography[key]||{};const formats=(st.format??saved.format??'').split(/[ ,]+/).filter(Boolean);return {family:st.family||saved.family||'Ubuntu',style:formats.includes('italic')?'italic':(saved.style||'normal'),size:(st.size||saved.size||fallback.size)+'px',weight:formats.includes('bold')?700:(saved.weight||fallback.weight),decoration:formats.includes('underline')?'underline':'none',color:st.color||saved.color||fallback.color};};
          const titleStyle=previewTypography('title',{size:20,weight:700,color:'#f7eef0'});const titlePreviewElement=document.getElementById('preview-header-title');Object.assign(titlePreviewElement.style,{fontFamily:titleStyle.family,fontStyle:titleStyle.style,fontSize:titleStyle.size,fontWeight:titleStyle.weight,textDecoration:'none',color:titleStyle.color});
          form.querySelectorAll('[data-rich-editor]').forEach(editor=>{const target=form.querySelector('[data-rich-value="'+editor.dataset.richEditor+'"]');if(target)target.value=editor.innerHTML;});
          const titlePreview=document.getElementById('preview-header-title');renderTitlePreview();
          // Keep the base family on the title container as a normal inherited
          // value. Per-range spans carry their own font-family declarations.
          titlePreview.style.fontFamily=titleStyle.family||'Ubuntu';
          document.getElementById('preview-hidden-left').innerHTML=form.querySelector('[data-rich-editor="hidden_left"]').innerHTML;
          document.getElementById('preview-hidden-right').innerHTML=form.querySelector('[data-rich-editor="hidden_right"]').innerHTML;
          const nav=document.getElementById('preview-header-links'); nav.querySelectorAll('.custom-preview-link').forEach(a=>a.remove());
          [...links.querySelectorAll('.header-link-row')].forEach(row=>{const label=editorText(row).trim();const url=row.querySelector('[name="header_link_url[]"]').value.trim();if(label){const a=document.createElement('a');a.className='custom-preview-link';a.textContent=label;a.href=url||'#';nav.insertBefore(a,nav.querySelector('.preview-signin'));}});
          const logo=document.getElementById('preview-header-logo');
          const logoWidth=form.elements.logo_width.value,logoHeight=form.elements.logo_height.value;
          logo.style.width=logoWidth+'px';logo.style.height=logoHeight+'px';logo.style.objectFit='contain';
          document.getElementById('logo-width-value').textContent=logoWidth+' px';
          document.getElementById('logo-height-value').textContent=logoHeight+' px';
        };
        const attachColorPalette=(input)=>{
          if(!input||input.dataset.paletteReady)return;
          input.dataset.paletteReady='1';
          const button=document.createElement('button');
          button.type='button';button.className='font-color-palette';
          button.setAttribute('aria-label','Open font color palette');
          button.setAttribute('aria-haspopup','true');button.setAttribute('aria-expanded','false');
          button.title='Choose font color';
          button.innerHTML='<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 3a9 9 0 1 0 0 18h1.2a2.3 2.3 0 0 0 1.6-3.9 1.2 1.2 0 0 1 .9-2.1H18a3 3 0 0 0 3-3c0-5-4-8-9-9Z"/><circle cx="7.5" cy="10" r="1"/><circle cx="10" cy="6.8" r="1"/><circle cx="15" cy="7.5" r="1"/><circle cx="17.5" cy="11" r="1"/></svg>';
          const palette=document.createElement('div');
          palette.className='font-color-palette-popover';palette.hidden=true;
          palette.setAttribute('role','group');palette.setAttribute('aria-label','Font color choices');
          const colors=['#ffffff','#f7eef0','#e8dce0','#d9a6b2','#c83e5b','#92243a','#5b1427','#171115','#000000','#f4c542','#55a66b','#4285d4','#8b5cf6','#ec6b2d'];
          colors.forEach(color=>{
            const swatch=document.createElement('button');swatch.type='button';
            swatch.className='font-color-swatch';swatch.style.setProperty('--swatch-color',color);
            swatch.setAttribute('aria-label','Use '+color);swatch.title=color;
            swatch.addEventListener('mousedown',event=>event.preventDefault());
            swatch.addEventListener('click',()=>{
              input.value=color;input.dispatchEvent(new Event('input',{bubbles:true}));
              input.dispatchEvent(new Event('change',{bubbles:true}));
              palette.hidden=true;button.setAttribute('aria-expanded','false');button.style.setProperty('--palette-color',color);
            });
            palette.append(swatch);
          });
          const custom=document.createElement('button');custom.type='button';custom.className='font-color-custom';custom.textContent='Custom…';
          custom.addEventListener('mousedown',event=>event.preventDefault());
          custom.addEventListener('click',()=>{try{if(input.showPicker)input.showPicker();else input.click();}catch(error){input.click();}});
          palette.append(custom);
          input.classList.add('font-color-native');
          input.insertAdjacentElement('afterend',button);button.insertAdjacentElement('afterend',palette);
          const sync=()=>{button.style.setProperty('--palette-color',input.value);};
          button.addEventListener('mousedown',event=>{event.preventDefault();rememberTitleSelection?.();});
          button.addEventListener('click',()=>{
            rememberTitleSelection?.();
            palette.hidden=!palette.hidden;button.setAttribute('aria-expanded',String(!palette.hidden));
          });
          input.addEventListener('input',sync);input.addEventListener('change',sync);sync();
        };

        // Selected-text typography is preview-only: keep the editor itself untouched.
        const titleEditor=form.querySelector('[data-rich-editor="title"]');
        const titlePreview=document.getElementById('preview-header-title');
        // Bind the independent controls to the Header Title fieldset explicitly.
        // This reference was previously missing and caused a ReferenceError that
        // stopped the entire selected-text typography workflow from initializing.
        const titleTypographyGroup=titleEditor?.closest('.header-field')?.querySelector('fieldset.typography-group') || null;
        let savedTitleSelection=null;
        const titlePreviewStyles=[];
        // Rebuild selected-range formatting from the last saved title HTML so the
        // preview remains identical after a save or a later visit to this tab.
        const savedTitleHtml=<?=json_encode((string)($headerSettings['title_html'] ?? ''),JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT)?>;
        let titlePreviewHasUserEdits=false;
        let titlePreviewFirstRender=true;
        if(savedTitleHtml){
          const savedTemplate=document.createElement('template');
          savedTemplate.innerHTML=savedTitleHtml;
          let savedOffset=0;
          const readSavedTitleNodes=(node,inherited={})=>{
            if(node.nodeType===Node.TEXT_NODE){
              const value=node.nodeValue||'';
              if(value.length&&Object.keys(inherited).length)titlePreviewStyles.push({start:savedOffset,end:savedOffset+value.length,styles:{...inherited}});
              savedOffset+=value.length;
              return;
            }
            if(node.nodeType!==Node.ELEMENT_NODE&&node.nodeType!==Node.DOCUMENT_FRAGMENT_NODE)return;
            const next={...inherited};
            if(node.nodeType===Node.ELEMENT_NODE){
              const tag=node.tagName.toLowerCase(),style=node.style;
              if(tag==='b'||tag==='strong')next.fontWeight='700';
              if(tag==='i'||tag==='em')next.fontStyle='italic';
              if(tag==='u')next.textDecoration='underline';
              if(style.color)next.color=style.color;
              if(style.fontFamily)next.fontFamily=style.fontFamily;
              if(style.fontSize)next.fontSize=style.fontSize;
              if(style.fontWeight)next.fontWeight=style.fontWeight;
              if(style.fontStyle)next.fontStyle=style.fontStyle;
              if(style.textDecoration)next.textDecoration=style.textDecoration;
            }
            [...node.childNodes].forEach(child=>readSavedTitleNodes(child,next));
          };
          readSavedTitleNodes(savedTemplate.content);
        }
        const readTitleSelection=()=>{
          const start=titleEditor.selectionStart;
          const end=titleEditor.selectionEnd;
          return Number.isInteger(start)&&Number.isInteger(end)&&end>start?{start,end}:null;
        };
        const rememberTitleSelection=()=>{
          const next=readTitleSelection();
          if(next)savedTitleSelection=next;
        };
        ['select','keyup','mouseup','touchend'].forEach(eventName=>titleEditor.addEventListener(eventName,rememberTitleSelection));
        // Textarea selectionStart/selectionEnd remain reliable even after a typography
        // control receives focus, unlike document.getSelection() on contenteditable.
        const captureTitleRangeBeforeControlFocus=event=>{
          if(!titleTypographyGroup || !titleTypographyGroup.contains(event.target))return;
          // Capture while the textarea still owns focus; native select controls
          // collapse its live selection as soon as they receive focus.
          rememberTitleSelection();
        };
        document.addEventListener('pointerdown',captureTitleRangeBeforeControlFocus,true);
        document.addEventListener('mousedown',captureTitleRangeBeforeControlFocus,true);
        document.addEventListener('touchstart',captureTitleRangeBeforeControlFocus,{capture:true,passive:true});
        titleTypographyGroup?.addEventListener('focusin',()=>{
          // Do not replace the saved range with the now-collapsed textarea range.
          if(!savedTitleSelection)rememberTitleSelection();
        });
        const escapePreviewText=value=>String(value).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
        const renderTitlePreview=()=>{
          const text=titleEditor.value||'';
          const richField=form.querySelector('[data-rich-value="title"]');
          // On first load, show the exact HTML that was saved in the database.
          // This avoids a lossy reconstruction of nested/overlapping inline styles
          // and guarantees the preview starts from the persisted configuration.
          if(titlePreviewFirstRender && !titlePreviewHasUserEdits && savedTitleHtml){
            titlePreview.innerHTML=savedTitleHtml;
            if(richField)richField.value=savedTitleHtml;
            titlePreviewFirstRender=false;
            return;
          }
          titlePreviewFirstRender=false;
          const baseFormats=new Set((titleTypographyGroup?.querySelector('[data-format-value]')?.value||'').split(/[ ,]+/).filter(Boolean));
          const boundaries=new Set([0,text.length]);
          titlePreviewStyles.forEach(item=>{
            boundaries.add(Math.max(0,Math.min(text.length,item.start)));
            boundaries.add(Math.max(0,Math.min(text.length,item.end)));
          });
          const points=[...boundaries].sort((a,b)=>a-b);
          let html='';
          for(let i=0;i<points.length-1;i++){
            const start=points[i],end=points[i+1];
            if(end<=start)continue;
            const styles={};
            if(baseFormats.has('bold'))styles.fontWeight='700';
            if(baseFormats.has('italic'))styles.fontStyle='italic';
            if(baseFormats.has('underline'))styles.textDecoration='underline';
            titlePreviewStyles.filter(item=>item.start<end&&item.end>start).forEach(item=>Object.assign(styles,item.styles));
            const underline=styles.textDecoration==='underline';
            delete styles.textDecoration;
            const content=escapePreviewText(text.slice(start,end));
            const declarations=Object.entries(styles).map(([key,value])=>{
              const cssName=key.replace(/[A-Z]/g,m=>'-'+m.toLowerCase());
              return cssName+':'+String(value);
            }).join(';');
            const formatted=underline?'<u>'+content+'</u>':content;
            html+=declarations?'<span style="'+declarations+'">'+formatted+'</span>':formatted;
          }
          titlePreview.innerHTML=html||escapePreviewText(text);
          if(richField)richField.value=html||escapePreviewText(text);
        };
        const applyTitleSelectionStyle=(property,value)=>{
          if(!savedTitleSelection||savedTitleSelection.end<=savedTitleSelection.start)return false;
          const textLength=titleEditor.value.length;
          const start=Math.max(0,Math.min(textLength,savedTitleSelection.start));
          const end=Math.max(start,Math.min(textLength,savedTitleSelection.end));
          if(end<=start)return false;
          // Add a range override instead of mutating a matching interval. This
          // preserves earlier formatting outside the newly selected text, even
          // when the new selection partially overlaps an existing formatted range.
          titlePreviewHasUserEdits=true;
          titlePreviewFirstRender=false;
          titlePreviewStyles.push({start,end,styles:{[property]:value}});
          renderTitlePreview();
          return true;
        };
        const isTitleRangeFullyStyled=(property,expectedValue)=>{
          if(!savedTitleSelection||savedTitleSelection.end<=savedTitleSelection.start)return false;
          const start=Math.max(0,Math.min(titleEditor.value.length,savedTitleSelection.start));
          const end=Math.max(start,Math.min(titleEditor.value.length,savedTitleSelection.end));
          if(end<=start)return false;
          const baseFormats=new Set((titleTypographyGroup?.querySelector('[data-format-value]')?.value||'').split(/[ ,]+/).filter(Boolean));
          for(let offset=start;offset<end;offset++){
            const styles={};
            titlePreviewStyles.filter(entry=>entry.start<=offset&&entry.end>offset).forEach(entry=>Object.assign(styles,entry.styles));
            let actual=styles[property];
            if(actual===undefined){
              if(property==='fontWeight')actual=baseFormats.has('bold')?'700':'400';
              else if(property==='fontStyle')actual=baseFormats.has('italic')?'italic':'normal';
              else if(property==='textDecoration')actual=baseFormats.has('underline')?'underline':'none';
            }
            if(String(actual)!==String(expectedValue))return false;
          }
          return true;
        };
        // Attach palettes only after selection helpers exist, so opening and choosing
        // a color can preserve and format the exact highlighted textarea range.
        titleTypographyGroup?.querySelectorAll('input[type="color"]').forEach(attachColorPalette);

        titleTypographyGroup?.querySelectorAll('[data-format-toggle]').forEach(button=>{
          // Snapshot the textarea range before the browser moves focus to the
          // toolbar button. Preventing the pointer default also keeps the
          // highlighted text visibly selected while the command is applied.
          button.addEventListener('pointerdown',event=>{rememberTitleSelection();event.preventDefault();});
          button.addEventListener('mousedown',event=>{rememberTitleSelection();event.preventDefault();});
          button.addEventListener('click',()=>{
            const currentRange=readTitleSelection();
            if(currentRange)savedTitleSelection=currentRange;
            const fmt=button.dataset.formatToggle;
            if(savedTitleSelection&&savedTitleSelection.end>savedTitleSelection.start){
              const prop=fmt==='bold'?'fontWeight':fmt==='italic'?'fontStyle':'textDecoration';
              const onValue=fmt==='bold'?'700':fmt==='italic'?'italic':'underline';
              const offValue=fmt==='bold'?'400':fmt==='italic'?'normal':'none';
              // Mixed selections become uniformly formatted on the first click;
              // a second click removes that formatting from the same selection.
              const alreadyApplied=isTitleRangeFullyStyled(prop,onValue);
              const nextValue=alreadyApplied?offValue:onValue;
              if(applyTitleSelectionStyle(prop,nextValue)){
                button.setAttribute('aria-pressed',String(!alreadyApplied));
                updatePreview();
                return;
              }
            }
            const hidden=titleTypographyGroup.querySelector('[data-format-value]');
            const set=new Set((hidden?.value||'').split(/[ ,]+/).filter(Boolean));
            const active=set.has(fmt);
            active?set.delete(fmt):set.add(fmt);
            if(hidden)hidden.value=['bold','italic','underline'].filter(x=>set.has(x)).join(' ');
            button.setAttribute('aria-pressed',String(!active));
            updatePreview();
          });
        });
        // Every other Independent Typography panel must also refresh the preview live.
        form.querySelectorAll('.typography-group').forEach(group=>{
          if(group===titleTypographyGroup)return;
          group.querySelectorAll('select,input[type="color"]').forEach(input=>{
            input.addEventListener('input',updatePreview);
            input.addEventListener('change',updatePreview);
          });
          group.querySelectorAll('[data-format-toggle]').forEach(button=>{
            button.addEventListener('mousedown',event=>event.preventDefault());
            button.addEventListener('click',()=>{
              const hidden=group.querySelector('[data-format-value]');
              const format=button.dataset.formatToggle;
              const formats=new Set((hidden?.value||'').split(/[ ,]+/).filter(Boolean));
              const active=formats.has(format);
              active?formats.delete(format):formats.add(format);
              if(hidden)hidden.value=['bold','italic','underline'].filter(item=>formats.has(item)).join(' ');
              button.setAttribute('aria-pressed',String(!active));
              updatePreview();
            });
          });
        });
        titleTypographyGroup?.querySelectorAll('select[name$="[family]"],select[name$="[size]"],input[name$="[color]"]').forEach(input=>{
          const applyControlValue=()=>{
            const range=savedTitleSelection;
            if(!range||range.end<=range.start){updatePreview();return;}
            const prop=input.name.endsWith('[family]')?'fontFamily':input.name.endsWith('[size]')?'fontSize':'color';
            const value=input.name.endsWith('[size]')?input.value+'px':input.value;
            if(applyTitleSelectionStyle(prop,value))updatePreview();
            else updatePreview();
          };
          // Selects commit once on change. Color inputs also emit input while
          // the picker is adjusted, so they update continuously.
          if(input.matches('input[type="color"]'))input.addEventListener('input',applyControlValue);
          input.addEventListener('change',applyControlValue);
        });
        const initRichEditors=()=>{
          form.querySelectorAll('[data-rich-editor]').forEach(editor=>{
            if(editor.dataset.ready)return;editor.dataset.ready='1';
            const key=editor.dataset.richEditor;
            const hidden=form.querySelector('[data-rich-value="'+key+'"]');editor.addEventListener('input',()=>{if(hidden)hidden.value=key==='title'?(form.querySelector('[data-rich-value="title"]')?.value||escapePreviewText(editor.value)):editor.innerHTML;if(key==='title'){titlePreviewHasUserEdits=true;titlePreviewFirstRender=false;titlePreviewStyles.length=0;savedTitleSelection=null;}const plain=key==='title'?editor.value.trim():editor.innerText.trim();const plainField=form.querySelector('[data-plain-value="'+key+'"]');if(plainField)plainField.value=plain;updatePreview();});
          });
          form.querySelectorAll('[data-style-controls="link"]').forEach((box)=>{
            if(box.dataset.ready)return;box.dataset.ready='1';const row=box.closest('.header-link-row');const idx=[...links.querySelectorAll('.header-link-row')].indexOf(row);let saved={};try{saved=JSON.parse(box.dataset.typography||'{}')}catch(e){}
            const fields=[['family','Font family','select',['Ubuntu','Georgia','Arial','Roboto','Verdana','Tahoma']],['size','Font size','select',[8,9,10,11,12,13,14,16,18,20,24,28,32,36,40]],['color','Font color','color',[]],['format','Font formatting','format-icons',[]]];
            fields.forEach(([key,label,type,options])=>{if(type==='format-icons'){const wrap=document.createElement('div');wrap.className='font-format-tools';wrap.setAttribute('role','group');wrap.setAttribute('aria-label','Font formatting');const hidden=document.createElement('input');hidden.type='hidden';hidden.name='typography[links]['+idx+'][format]';hidden.dataset.formatValue='';hidden.value=saved.format||'';['bold','italic','underline'].forEach(fmt=>{const b=document.createElement('button');b.type='button';b.className='format-icon';b.dataset.formatToggle=fmt;b.setAttribute('aria-label',fmt);b.setAttribute('aria-pressed',String(hidden.value.split(/[ ,]+/).includes(fmt)));b.innerHTML=fmt==='bold'?'<b>B</b>':fmt==='italic'?'<i>I</i>':'<u>U</u>';b.addEventListener('mousedown',e=>e.preventDefault());b.addEventListener('click',()=>{const set=new Set(hidden.value.split(/[ ,]+/).filter(Boolean));set.has(fmt)?set.delete(fmt):set.add(fmt);hidden.value=['bold','italic','underline'].filter(x=>set.has(x)).join(' ');b.setAttribute('aria-pressed',String(set.has(fmt)));updatePreview();});wrap.append(b);});wrap.append(hidden);box.append(wrap);return;}const wrap=document.createElement('label');wrap.textContent=label;const input=document.createElement(type==='color'?'input':'select');input.name='typography[links]['+idx+']['+key+']';if(type==='color'){input.type='color';input.value=saved[key]||'#e8dce0';attachColorPalette(input)}else{options.forEach(v=>{const o=document.createElement('option');o.value=v;o.textContent=key==='size'?v+' px':v;input.append(o)});input.value=saved[key]|| (key==='family'?'Ubuntu':key==='size'?13:'normal')}input.addEventListener('change',updatePreview);wrap.append(input);box.append(wrap);});
          });
        };
        initRichEditors();
        updatePreview();
        add.addEventListener('click',()=>{links.append(makeLinkRow());initRichEditors();updatePreview();});
        links.querySelectorAll('.remove-header-link').forEach(button=>button.addEventListener('click',()=>{button.closest('.header-link-row').remove();updatePreview();}));
        // Synchronize every editable Header field immediately before POST. This prevents
        // stale hidden inputs from overwriting the values currently visible in the editors.
        form.addEventListener('submit', () => {
          form.querySelectorAll('[data-rich-editor]').forEach(editor => {
            const key = editor.dataset.richEditor;
            const plain = key === 'title' ? editor.value.trim() : editor.innerText.trim();
            const rich = key === 'title' ? (form.querySelector('[data-rich-value="title"]')?.value || escapePreviewText(editor.value)) : editor.innerHTML;
            const plainField = editor.closest('.header-link-row')
              ? editor.closest('.header-link-row').querySelector('[data-plain-value="link"]')
              : form.querySelector('[data-plain-value="' + key + '"]');
            const richField = editor.closest('.header-link-row')
              ? editor.closest('.header-link-row').querySelector('[data-rich-value="link"]')
              : form.querySelector('[data-rich-value="' + key + '"]');
            if (plainField) plainField.value = plain;
            if (richField) richField.value = rich;
          });
          form.querySelectorAll('.header-link-row').forEach((row, index) => {
            row.querySelectorAll('[name^="typography[links]"]').forEach(field => {
              field.name = field.name.replace(/typography\[links\]\[\d+\]/, 'typography[links][' + index + ']');
            });
          });
        });
        form.addEventListener('input', updatePreview);
        form.addEventListener('change', updatePreview);
        const fileInput=document.getElementById('header-logo-input');fileInput.addEventListener('change',()=>{const file=fileInput.files[0];if(!file)return;if(!['image/png','image/jpeg'].includes(file.type)){alert('Choose a PNG, JPG or JPEG image.');fileInput.value='';return;}const reader=new FileReader();reader.onload=()=>{const img=document.getElementById('preview-header-logo');img.src=reader.result;img.style.display='block';updatePreview();};reader.readAsDataURL(file);});
        updatePreview();
      }
    })();
    const paletteStyle=document.createElement('style');paletteStyle.textContent=".font-color-palette-popover{position:absolute;z-index:1000;display:grid;grid-template-columns:repeat(7,25px);gap:5px;padding:9px;margin-top:5px;border:1px solid rgba(220,174,187,.4);border-radius:10px;background:#171115;box-shadow:0 10px 28px rgba(0,0,0,.45)}.font-color-palette-popover[hidden]{display:none}.font-color-swatch{width:25px;height:25px;border:1px solid rgba(255,255,255,.35);border-radius:6px;background:var(--swatch-color);cursor:pointer}.font-color-swatch:focus-visible,.font-color-custom:focus-visible{outline:2px solid #d99aaa;outline-offset:2px}.font-color-custom{grid-column:1/-1;padding:6px;border:1px solid rgba(220,174,187,.35);border-radius:6px;background:#26171d;color:#fff;cursor:pointer;font:11px Ubuntu,sans-serif}.font-color-palette{display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;margin-left:7px;padding:7px;border:1px solid rgba(220,174,187,.35);border-radius:9px;background:rgba(91,20,39,.22);color:var(--palette-color,#e8dce0);cursor:pointer;vertical-align:middle;transition:background .2s,border-color .2s,transform .2s}.font-color-palette:hover{background:rgba(130,30,57,.35);border-color:rgba(220,174,187,.7);transform:translateY(-1px)}.font-color-palette:focus-visible{outline:2px solid #d99aaa;outline-offset:2px}.font-color-palette svg{width:20px;height:20px;fill:none;stroke:currentColor;stroke-width:1.7;stroke-linecap:round;stroke-linejoin:round}.font-color-native{position:absolute!important;width:1px!important;height:1px!important;padding:0!important;margin:-1px!important;overflow:hidden!important;clip:rect(0,0,0,0)!important;white-space:nowrap!important;border:0!important}";document.head.append(paletteStyle);

    </script>
  <?php else: $info=$settings[$section]; ?>
    <section class="panel admin-panel"><div class="symbol" style="font-size:30px;color:var(--gold)"><?=e($info[2])?></div><h2 style="margin-top:12px"><?=e($info[0])?></h2><p class="muted"><?=e($info[1])?></p><div class="admin-note">This Settings tab is ready for its management controls. No existing records have been changed.</div></section>
  <?php endif; ?>
<?php endif;?>
<script>
(()=> {
  const toggle = document.getElementById('settings-menu-toggle');
  const submenu = document.getElementById('settings-submenu');
  if (!toggle || !submenu) return;
  toggle.addEventListener('click', () => {
    const expanded = toggle.getAttribute('aria-expanded') === 'true';
    const shouldExpand = !expanded;

    // Settings is the only active top-level item while its submenu is open.
    toggle.closest('.admin-nav')?.querySelectorAll(':scope > a').forEach(item => {
      item.classList.remove('active');
    });
    toggle.classList.toggle('active', shouldExpand);
    toggle.setAttribute('aria-expanded', String(shouldExpand));
    submenu.classList.toggle('is-open', shouldExpand);
  });
})();

(()=>{['account-status-modal','banner-settings-success-modal','header-settings-success-modal','footer-settings-success-modal','footer-validation-modal'].forEach(id=>{const modal=document.getElementById(id);if(!modal)return;const closeButton=modal.querySelector('button');const close=()=>modal.remove();closeButton?.addEventListener('click',close);modal.addEventListener('click',event=>{if(event.target===modal)close();});document.addEventListener('keydown',event=>{if(event.key==='Escape')close();});closeButton?.focus();});})();
</script>
</main></div><script>

</script>
</body></html>
