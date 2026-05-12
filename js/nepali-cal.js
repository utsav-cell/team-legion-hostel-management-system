/* Standard calendar widget — renders into #nepali-cal */
(function () {
    var MONTHS = [
        'January','February','March','April','May','June',
        'July','August','September','October','November','December'
    ];
    var WDAYS = ['Su','Mo','Tu','We','Th','Fr','Sa'];

    function render() {
        var el = document.getElementById('nepali-cal');
        if (!el) return;

        var now   = new Date();
        var year  = now.getFullYear();
        var month = now.getMonth();      // 0-indexed
        var today = now.getDate();

        var first    = new Date(year, month, 1).getDay(); // 0=Sun
        var daysInMonth = new Date(year, month + 1, 0).getDate();

        var html = '<div class="ncal-head">'
                 + '<span class="ncal-month">' + MONTHS[month] + ' ' + year + '</span>'
                 + '</div>'
                 + '<div class="ncal-grid">';

        WDAYS.forEach(function(d){ html += '<div class="ncal-wd">' + d + '</div>'; });

        for (var i = 0; i < first; i++) html += '<div></div>';
        for (var d = 1; d <= daysInMonth; d++) {
            var cls = 'ncal-day' + (d === today ? ' ncal-today' : '');
            html += '<div class="' + cls + '">' + d + '</div>';
        }

        html += '</div>';
        html += '<div class="ncal-today-note">Today: ' + MONTHS[month] + ' ' + today + ', ' + year + '</div>';

        el.innerHTML = html;
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', render);
    } else {
        render();
    }
})();
