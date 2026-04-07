/**
 * MTTI Lecturer Portal JavaScript
 * Session timer, attendance marking, scheme week updates
 */
jQuery(document).ready(function($) {

    // ── DARK MODE (shared with learner portal) ──────────
    var DARK_KEY = 'mtti_dark_mode';
    function applyTheme(dark) {
        var $w = $('#mtti-lecturer-portal');
        if (dark) { $w.attr('data-theme','dark'); $('.mtti-dark-toggle').text('☀️'); }
        else       { $w.removeAttr('data-theme');  $('.mtti-dark-toggle').text('🌙'); }
    }
    var isDark = localStorage.getItem(DARK_KEY) !== null
        ? localStorage.getItem(DARK_KEY) === '1'
        : (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
    applyTheme(isDark);
    $(document).on('click', '.mtti-dark-toggle', function() {
        isDark = !isDark;
        localStorage.setItem(DARK_KEY, isDark ? '1' : '0');
        applyTheme(isDark);
    });

    // ── LIVE SESSION TIMER ──────────────────────────────
    var timerEl = document.getElementById('session-timer');
    if (timerEl) {
        var startTs    = parseInt(timerEl.dataset.start) * 1000;
        var plannedMin = parseInt(timerEl.dataset.planned) || 120;

        function updateTimer() {
            var now      = Date.now();
            var elapsed  = Math.floor((now - startTs) / 1000);
            var h = Math.floor(elapsed / 3600);
            var m = Math.floor((elapsed % 3600) / 60);
            var s = elapsed % 60;
            timerEl.textContent = pad(h) + ':' + pad(m) + ':' + pad(s);

            // Progress bar
            var pct = Math.min((elapsed / (plannedMin * 60)) * 100, 100);
            var fill = document.getElementById('session-progress-fill');
            if (fill) fill.style.width = pct + '%';

            // Overtime label
            var overLabel = document.getElementById('overtime-label');
            if (overLabel) {
                var overMin = Math.floor(elapsed / 60) - plannedMin;
                if (overMin > 0) {
                    overLabel.textContent = '⚠ ' + overMin + ' min over planned time';
                    overLabel.style.color = '#ffcccc';
                } else {
                    overLabel.textContent = Math.abs(overMin) + ' min remaining';
                }
            }
        }
        updateTimer();
        setInterval(updateTimer, 1000);
    }

    // ── LIVE DASHBOARD TIMER ────────────────────────────
    var liveEl = document.getElementById('live-timer');
    if (liveEl) {
        var liveStart = parseInt(liveEl.dataset.start) * 1000;
        setInterval(function() {
            var mins = Math.floor((Date.now() - liveStart) / 60000);
            var h = Math.floor(mins / 60), m = mins % 60;
            liveEl.textContent = 'Running ' + (h > 0 ? h + 'h ' : '') + m + 'm';
        }, 10000);
    }

    function pad(n) { return n < 10 ? '0' + n : n; }

    // ── LOAD SCHEME WEEKS when course selected ──────────
    $('#ci-course').on('change', function() {
        var cid = $(this).val();
        if (!cid) return;
        $.post(mttiLecturer.ajaxUrl, {
            action: 'mtti_get_session_students',
            nonce:  mttiLecturer.nonce,
            course_id: cid
        }, function(res) {
            if (res.success && res.data.weeks.length) {
                var sel = $('#ci-week').empty().append('<option value="">— Not linked —</option>');
                $.each(res.data.weeks, function(_, w) {
                    sel.append('<option value="' + w.week_id + '">W' + w.week_number + ': ' + w.topic + '</option>');
                });
                $('#scheme-week-selector').show();
            }
        });
    });
});

// ── CLOCK IN ────────────────────────────────────────────
function mttiClockIn() {
    var form = document.getElementById('clock-in-form');
    if (!form) return;
    var data = {
        action:        'mtti_lecturer_clock_in',
        nonce:         mttiLecturer.nonce,
        course_id:     form.querySelector('[name=course_id]').value,
        topic:         form.querySelector('[name=topic]').value,
        planned_hours: form.querySelector('[name=planned_hours]').value,
        notes:         form.querySelector('[name=notes]') ? form.querySelector('[name=notes]').value : '',
        week_id:       form.querySelector('[name=week_id]') ? form.querySelector('[name=week_id]').value : '',
    };
    if (!data.course_id || !data.topic) {
        alert('Please select a course and enter the topic.');
        return;
    }
    jQuery.post(mttiLecturer.ajaxUrl, data, function(res) {
        if (res.success) {
            location.reload();
        } else {
            alert('Error: ' + (res.data || 'Could not clock in.'));
        }
    });
}

// ── CLOCK OUT ───────────────────────────────────────────
function mttiClockOut(nonce, sessionId) {
    if (!confirm('Clock out now?')) return;
    jQuery.post(mttiLecturer.ajaxUrl, {
        action:     'mtti_lecturer_clock_out',
        nonce:      nonce,
        session_id: sessionId,
    }, function(res) {
        if (res.success) {
            var d = res.data;
            var msg = 'Session ended. Duration: ' + d.duration_hours + 'h.';
            if (d.over_under !== null) {
                msg += d.over_under > 0.2
                    ? ' ⚠ ' + d.over_under + 'h over planned.'
                    : (d.over_under < -0.2 ? ' ' + Math.abs(d.over_under) + 'h under.' : ' ✓ On time.');
            }
            alert(msg);
            location.reload();
        } else {
            alert(res.data || 'Could not clock out.');
        }
    });
}

// ── MARK INDIVIDUAL ATTENDANCE ──────────────────────────
function mttiSetAttendance(btn, studentId, status, nonce, attDate, courseId) {
    // Highlight the clicked button
    var parent = btn.closest('[data-student]');
    parent.querySelectorAll('.att-btn').forEach(function(b) {
        b.style.background = '';
        b.style.color      = '';
        b.style.borderColor = '';
    });
    var colors = { Present:'#2E7D32', Absent:'#C62828', Late:'#FF8F00', Excused:'#1565C0' };
    btn.style.background  = colors[status] || '#999';
    btn.style.color       = 'white';
    btn.style.borderColor = colors[status] || '#999';

    jQuery.post(mttiLecturer.ajaxUrl, {
        action:     'mtti_mark_attendance',
        nonce:      nonce,
        student_id: studentId,
        course_id:  courseId,
        att_date:   attDate,
        status:     status,
    }, function(res) {
        if (!res.success) {
            alert('Could not save attendance. Try again.');
            location.reload();
        }
    });
}

// ── MARK ALL ATTENDANCE ─────────────────────────────────
function mttiMarkAll(status, nonce, attDate, courseId) {
    var rows = document.querySelectorAll('#attendance-list [data-student]');
    rows.forEach(function(row) {
        var studentId = row.dataset.student;
        var btn = row.querySelector('[data-status="' + status + '"]');
        if (btn) mttiSetAttendance(btn, studentId, status, nonce, attDate, courseId);
    });
}

// ── MARK SCHEME WEEK STATUS ─────────────────────────────
function mttiMarkWeek(weekId, status, nonce) {
    jQuery.post(mttiLecturer.ajaxUrl, {
        action:  'mtti_update_week_status',
        nonce:   nonce,
        week_id: weekId,
        status:  status,
    }, function(res) {
        if (res.success) location.reload();
        else alert('Could not update week. Try again.');
    });
}
