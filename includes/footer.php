        </div><!-- /.content-inner -->
    </main><!-- /.main-content -->

    <!-- Right-side vertical sidebar nav -->
    <?php require_once __DIR__ . '/navbar.php'; ?>

</div><!-- /.layout-wrapper -->

<footer class="site-footer">
    <p>&copy; <?= date('Y') ?> <?= APP_NAME ?>. All rights reserved.</p>
</footer>

<!-- ============================================================
     FLOATING ISSUE REPORTER
     ============================================================ -->
<?php if (!empty($_SESSION['user_id'])): ?>

<!-- Floating trigger dot -->
<button id="feedback-trigger"
        title="Report an issue"
        aria-label="Report an issue"
        onclick="openFeedbackModal()"
        style="
            position:fixed;
            bottom:1.5rem;
            right:1.5rem;
            width:48px;
            height:48px;
            border-radius:50%;
            background:#1e40af;
            border:none;
            cursor:pointer;
            display:flex;
            align-items:center;
            justify-content:center;
            box-shadow:0 4px 16px rgba(30,64,175,.45);
            z-index:8000;
            transition:transform .15s,box-shadow .15s;
        "
        onmouseover="this.style.transform='scale(1.12)';this.style.boxShadow='0 6px 24px rgba(30,64,175,.55)'"
        onmouseout="this.style.transform='scale(1)';this.style.boxShadow='0 4px 16px rgba(30,64,175,.45)'">
    <!-- Bug icon -->
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none"
         stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M8 2l1.5 1.5"/>
        <path d="M14.5 3.5L16 2"/>
        <path d="M9 8a3 3 0 0 1 6 0v5a3 3 0 0 1-6 0V8z"/>
        <path d="M6.5 10H4a1 1 0 0 0-1 1v1a1 1 0 0 0 1 1h2.5"/>
        <path d="M17.5 10H20a1 1 0 0 1 1 1v1a1 1 0 0 1-1 1h-2.5"/>
        <path d="M6.5 17.5S5 18 5 20"/>
        <path d="M17.5 17.5S19 18 19 20"/>
        <path d="M9 17.5l-.5 3"/>
        <path d="M15 17.5l.5 3"/>
    </svg>
    <!-- Pulse ring -->
    <span style="
        position:absolute;
        inset:-4px;
        border-radius:50%;
        border:2px solid rgba(30,64,175,.4);
        animation:fb-pulse 2.2s ease-out infinite;
        pointer-events:none;
    "></span>
</button>

<!-- Modal overlay -->
<div id="feedback-overlay"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);
            z-index:8001;align-items:center;justify-content:center;padding:1rem;"
     onclick="if(event.target===this)closeFeedbackModal()">

    <div style="background:#fff;border-radius:14px;width:100%;max-width:480px;
                box-shadow:0 12px 48px rgba(0,0,0,.25);overflow:hidden;
                animation:fb-slide-in .2s ease-out;">

        <!-- Modal header -->
        <div style="background:#1e3a5f;padding:1rem 1.25rem;display:flex;align-items:center;justify-content:space-between;">
            <div style="display:flex;align-items:center;gap:.6rem;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none"
                     stroke="#93c5fd" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M8 2l1.5 1.5"/><path d="M14.5 3.5L16 2"/>
                    <path d="M9 8a3 3 0 0 1 6 0v5a3 3 0 0 1-6 0V8z"/>
                    <path d="M6.5 10H4a1 1 0 0 0-1 1v1a1 1 0 0 0 1 1h2.5"/>
                    <path d="M17.5 10H20a1 1 0 0 1 1 1v1a1 1 0 0 1-1 1h-2.5"/>
                    <path d="M6.5 17.5S5 18 5 20"/><path d="M17.5 17.5S19 18 19 20"/>
                    <path d="M9 17.5l-.5 3"/><path d="M15 17.5l.5 3"/>
                </svg>
                <span style="color:#fff;font-weight:700;font-size:.95rem;">Report an Issue</span>
            </div>
            <button onclick="closeFeedbackModal()"
                    style="background:none;border:none;color:#93c5fd;cursor:pointer;
                           font-size:1.2rem;line-height:1;padding:.2rem .4rem;border-radius:4px;"
                    onmouseover="this.style.color='#fff'" onmouseout="this.style.color='#93c5fd'">
                &#x2715;
            </button>
        </div>

        <!-- Form -->
        <form id="feedback-form" style="padding:1.25rem;" onsubmit="submitFeedback(event)">

            <div style="margin-bottom:1rem;">
                <label style="display:block;font-size:.82rem;font-weight:600;color:#374151;margin-bottom:.35rem;">
                    What happened? <span style="color:#dc2626;">*</span>
                </label>
                <textarea id="feedback-desc" name="description" rows="4"
                          placeholder="Describe the issue — what you expected vs. what happened…"
                          required
                          style="width:100%;padding:.6rem .75rem;border:1px solid #d1d5db;border-radius:7px;
                                 font-size:.875rem;font-family:inherit;resize:vertical;min-height:90px;
                                 outline:none;box-sizing:border-box;line-height:1.55;"
                          onfocus="this.style.borderColor='#2563eb';this.style.boxShadow='0 0 0 3px rgba(37,99,235,.15)'"
                          onblur="this.style.borderColor='#d1d5db';this.style.boxShadow='none'"></textarea>
            </div>

            <div style="margin-bottom:1.25rem;">
                <label style="display:block;font-size:.82rem;font-weight:600;color:#374151;margin-bottom:.35rem;">
                    Screenshot
                    <span style="font-weight:400;color:#6b7280;">(optional — JPG, PNG, max 5 MB)</span>
                </label>
                <div id="screenshot-drop"
                     onclick="document.getElementById('screenshot-input').click()"
                     style="border:2px dashed #d1d5db;border-radius:8px;padding:1rem;text-align:center;
                            cursor:pointer;transition:border-color .15s,background .15s;"
                     onmouseover="this.style.borderColor='#2563eb';this.style.background='#eff6ff'"
                     onmouseout="if(!window._fbHasFile){this.style.borderColor='#d1d5db';this.style.background='transparent'}">
                    <div id="screenshot-label" style="font-size:.82rem;color:#6b7280;">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#9ca3af"
                             stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"
                             style="display:block;margin:0 auto .4rem;">
                            <rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/>
                            <polyline points="21 15 16 10 5 21"/>
                        </svg>
                        Click to attach a screenshot
                    </div>
                    <input type="file" id="screenshot-input" name="screenshot"
                           accept="image/jpeg,image/png,image/gif,image/webp"
                           style="display:none;"
                           onchange="previewScreenshot(this)">
                </div>
            </div>

            <!-- Submit row -->
            <div style="display:flex;gap:.75rem;align-items:center;">
                <button type="submit" id="feedback-submit"
                        style="flex:1;padding:.6rem 1rem;background:#1e40af;color:#fff;border:none;
                               border-radius:7px;font-size:.9rem;font-weight:600;cursor:pointer;
                               transition:background .12s;"
                        onmouseover="this.style.background='#1d4ed8'" onmouseout="this.style.background='#1e40af'">
                    Send Report
                </button>
                <button type="button" onclick="closeFeedbackModal()"
                        style="padding:.6rem 1rem;background:transparent;color:#6b7280;
                               border:1px solid #e5e7eb;border-radius:7px;font-size:.9rem;cursor:pointer;">
                    Cancel
                </button>
            </div>

            <div id="feedback-msg" style="display:none;margin-top:.85rem;padding:.65rem .9rem;
                                          border-radius:7px;font-size:.85rem;"></div>
        </form>
    </div>
</div>

<style>
@keyframes fb-pulse {
    0%   { transform: scale(1);   opacity: .8; }
    70%  { transform: scale(1.5); opacity: 0;  }
    100% { transform: scale(1.5); opacity: 0;  }
}
@keyframes fb-slide-in {
    from { transform: translateY(16px) scale(.97); opacity: 0; }
    to   { transform: translateY(0)    scale(1);   opacity: 1; }
}
</style>

<script>
window._fbHasFile = false;

function openFeedbackModal() {
    var o = document.getElementById('feedback-overlay');
    o.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    setTimeout(function(){ document.getElementById('feedback-desc').focus(); }, 180);
}

function closeFeedbackModal() {
    document.getElementById('feedback-overlay').style.display = 'none';
    document.body.style.overflow = '';
    // Reset form
    document.getElementById('feedback-form').reset();
    document.getElementById('screenshot-label').innerHTML =
        '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#9ca3af" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="display:block;margin:0 auto .4rem;"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>Click to attach a screenshot';
    var drop = document.getElementById('screenshot-drop');
    drop.style.borderColor = '#d1d5db';
    drop.style.background  = 'transparent';
    window._fbHasFile = false;
    document.getElementById('feedback-msg').style.display = 'none';
    document.getElementById('feedback-submit').disabled = false;
    document.getElementById('feedback-submit').textContent = 'Send Report';
}

function previewScreenshot(input) {
    if (!input.files || !input.files[0]) return;
    var file = input.files[0];
    window._fbHasFile = true;
    var drop = document.getElementById('screenshot-drop');
    drop.style.borderColor = '#16a34a';
    drop.style.background  = '#f0fdf4';
    document.getElementById('screenshot-label').innerHTML =
        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:inline;vertical-align:middle;margin-right:4px;"><polyline points="20 6 9 17 4 12"/></svg>'
        + '<span style="color:#16a34a;font-weight:600;">' + file.name + '</span>'
        + ' <span style="color:#6b7280;font-size:.75rem;">(' + (file.size > 1048576 ? (file.size/1048576).toFixed(1)+' MB' : Math.round(file.size/1024)+' KB') + ')</span>'
        + '<br><span style="font-size:.75rem;color:#6b7280;margin-top:.2rem;display:block;">Click to change</span>';
}

function submitFeedback(e) {
    e.preventDefault();
    var btn  = document.getElementById('feedback-submit');
    var msg  = document.getElementById('feedback-msg');
    var desc = document.getElementById('feedback-desc').value.trim();

    if (!desc) {
        showFbMsg('Please enter a description.', 'error');
        return;
    }

    btn.disabled    = true;
    btn.textContent = 'Sending…';

    var fd = new FormData(document.getElementById('feedback-form'));
    fd.append('page_url',   window.location.href);
    fd.append('page_title', document.title);

    fetch('<?= BASE_URL ?>/feedback/report.php', { method: 'POST', body: fd })
        .then(function(r){ return r.json(); })
        .then(function(data) {
            if (data.ok) {
                showFbMsg('✓ Report sent — thank you! We\'ll look into it.', 'success');
                btn.style.display = 'none';
                setTimeout(closeFeedbackModal, 2200);
            } else {
                showFbMsg(data.error || 'Something went wrong. Please try again.', 'error');
                btn.disabled    = false;
                btn.textContent = 'Send Report';
            }
        })
        .catch(function() {
            showFbMsg('Network error. Please try again.', 'error');
            btn.disabled    = false;
            btn.textContent = 'Send Report';
        });
}

function showFbMsg(text, type) {
    var el = document.getElementById('feedback-msg');
    el.textContent  = text;
    el.style.display = 'block';
    el.style.background = type === 'success' ? '#f0fdf4' : '#fef2f2';
    el.style.border     = '1px solid ' + (type === 'success' ? '#86efac' : '#fca5a5');
    el.style.color      = type === 'success' ? '#166534' : '#dc2626';
}

document.addEventListener('keydown', function(e){
    if (e.key === 'Escape') closeFeedbackModal();
});
</script>

<?php endif; ?>

<script src="<?= BASE_URL ?>/assets/js/main.js"></script>
</body>
</html>
