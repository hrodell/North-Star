(function($){
/**
 * Admin Calendar UI + Actions + Season Lock + Add New Timeslot
 * file: northstar-admin-ui.js
 * - Calendar view/date preserved across refresh
 * - Tile layout (5 rows): time, Capacity, Booked + (%), Remaining, Actions
 * - Actions: Edit (capacity+time only), Duplicate (mini FullCalendar), Delete, Block/Unblock
 * - New: Bookings modal (read-only) with headers always visible
 * - Add New Timeslot: Selected Date field + day highlight (Delivery green / Removal blue)
 * - Time inputs: 15-min increments (step + validation) + "Enter" buttons to apply/preview
 */

var __NS_VIEW_TYPE = null;   // preserved calendar view (month/week/day)
var __NS_VIEW_DATE = null;   // preserved calendar date (Date object)
var calendar = null;

function preserveState(){
  if (calendar){
    __NS_VIEW_TYPE = calendar.view.type;
    __NS_VIEW_DATE = calendar.getDate();
  }
}
function refresh(){ preserveState(); loadCalendar(); }

function clsByPct(p){ if(p>=100)return'ns-util--100'; if(p>=86)return'ns-util--86-99'; if(p>=76)return'ns-util--76-85'; return'ns-util--0-75'; }
function typeClass(t){ return t==='Delivery'?'ns-slot--delivery':(t==='Removal'?'ns-slot--removal':''); }
function toLabel(hhmm){ var x=hhmm.split('-'); function f(s){ var p=s.split(':'),h=+p[0],m=p[1],ap=h>=12?'PM':'AM'; h=h%12; if(h===0)h=12; return h+':'+m+' '+ap; } return f(x[0])+' – '+f(x[1]); }
function isQuarter(hhmm){ var m = parseInt(hhmm.split(':')[1],10); return [0,15,30,45].indexOf(m)>=0; }

function allowedWindows(type, dateISO){
  if (!dateISO) return [];
  if (type==='Removal') return ['08:00-17:00'];
  var d = new Date(dateISO+'T00:00:00'); var wd = d.getDay(); // 0=Sun..6=Sat
  if (wd>=1 && wd<=4) return ['08:00-12:00','12:00-19:00','19:00-21:00'];
  return ['08:00-20:00'];
}

function eventFromRow(r){
  var remaining = Math.max((+r.capacity) - (+r.booked), 0);
  var utilPct = (r.capacity>0) ? Math.round((+r.booked / +r.capacity)*100) : 100;
  return {
    id: r.id,
    start: r.slot_date+'T'+r.time_window.split('-')[0]+':00',
    end:   r.slot_date+'T'+r.time_window.split('-')[1]+':00',
    title: toLabel(r.time_window),
    extendedProps: {
      slotId:+r.id, slotDate:r.slot_date, window:r.time_window, type:r.type,
      capacity:+r.capacity, booked:+r.booked, remaining:remaining,
      blocked:!!(+r.blocked), utilPct:utilPct
    }
  };
}

function renderEventContent(arg){
  var p = arg.event.extendedProps;
  var label = toLabel(p.window);
  var pct = p.utilPct + '%';
  var remainingCls = (p.remaining===0) ? 'ns-remaining-zero' : '';
  var lock = p.blocked ? '<span class="ns-lock" title="Blocked" aria-label="Blocked">🔒</span>' : '';

  var disabledDelete = (p.booked>0) || window.__NS_LOCKED__;
  var disabledDup    = (p.booked>0) || window.__NS_LOCKED__;

  var actions =
    '<div class="ns-row5">'+
      '<button class="ns-btn js-ns-edit"        data-id="'+p.slotId+'">Edit</button>'+
      '<button class="ns-btn js-ns-duplicate"   data-id="'+p.slotId+'" '+(disabledDup?'disabled':'')+'>Duplicate</button>'+
      '<button class="ns-btn ns-btn--danger js-ns-delete" data-id="'+p.slotId+'" '+(disabledDelete?'disabled':'')+'>Delete</button>'+
      '<button class="ns-btn js-ns-toggle"      data-id="'+p.slotId+'" data-blocked="'+(p.blocked?1:0)+'">'+(p.blocked?'Unblock':'Block')+'</button>'+
      '<button class="ns-btn js-ns-booked"      data-id="'+p.slotId+'">Booked</button>'+
    '</div>';

  var html =
    '<div class="ns-event">'+
      lock+
      '<div class="ns-row1"><strong>'+label+'</strong></div>'+
      '<div class="ns-row2">Capacity: '+p.capacity+'</div>'+
      '<div class="ns-row3">Booked: '+p.booked+' <span class="ns-slot__pct">('+pct+')</span></div>'+
      '<div class="ns-row4">Remaining: <span class="'+remainingCls+'">'+p.remaining+'</span></div>'+
      actions+
    '</div>';

  var el = document.createElement('div'); el.innerHTML = html;
  return { domNodes:[el] };
}

/* ----- Modal helpers ----- */
function showModal(){ $('#nsds-modal').removeClass('nsds-hidden').attr('aria-hidden','false'); }
function hideModal(){ $('#nsds-modal').addClass('nsds-hidden').attr('aria-hidden','true'); }
function showDupModal(){ $('#nsds-dup-modal').removeClass('nsds-hidden').attr('aria-hidden','false'); }
function hideDupModal(){ $('#nsds-dup-modal').addClass('nsds-hidden').attr('aria-hidden','true'); if (window.__dupCal){ window.__dupCal.destroy(); window.__dupCal=null; } }

function showAddModal(){ $('#nsds-add-modal').removeClass('nsds-hidden').attr('aria-hidden','false'); }
function hideAddModal(){ $('#nsds-add-modal').addClass('nsds-hidden').attr('aria-hidden','true'); if (window.__addCal){ window.__addCal.destroy(); window.__addCal=null; } $('#nsds-add-preview').val(''); $('#nsds-add-start,#nsds-add-end').val(''); $('#nsds-add-create').data('date',''); $('#nsds-add-date').val(''); clearAddHighlight(); }

function showBookedModal(){ $('#nsds-booked-modal').removeClass('nsds-hidden').attr('aria-hidden','false'); }
function hideBookedModal(){ $('#nsds-booked-modal').addClass('nsds-hidden').attr('aria-hidden','true'); $('#nsds-booked-table').empty(); }

/* ----- Edit form helpers ----- */
function hhmmFromInputs($start,$end){
  var s=$start.val()||'', e=$end.val()||'';
  if (!/^\d{2}:\d{2}$/.test(s) || !/^\d{2}:\d{2}$/.test(e)) return null;
  if (!isQuarter(s) || !isQuarter(e)) return null;
  if (s>=e) return null;
  return s+'-'+e;
}
function setEditEnabled(enabled){
  $('#nsds-start,#nsds-end,#nsds-start-enter,#nsds-end-enter').prop('disabled', !enabled);
}
function setForm(data){
  // data: {slotId,type,date,window,capacity,booked}
  $('#nsds-slot-id').val(data.slotId||'');
  $('#nsds-type-readonly').val(data.type||'');
  $('#nsds-date-readonly').val(data.date||'');
  $('#nsds-capacity').val(data.capacity!=null?data.capacity:25);

  var parts = (data.window||'').split('-');
  $('#nsds-start').val(parts[0]||'');
  $('#nsds-end').val(parts[1]||'');
  $('#nsds-preview').val( (parts[0]&&parts[1]) ? toLabel(data.window) : '' );

  var canEditTime = (data.booked||0)===0 && !window.__NS_LOCKED__;
  setEditEnabled(canEditTime);

  $('#nsds-delete').prop('disabled', (data.booked||0)>0 || window.__NS_LOCKED__);
}

/* ----- Calendar loader ----- */
function loadCalendar(){
  window.__NS_LOCKED__ = !!(+NSDS_ADMIN.seasonLock);

  $('#nsds-export-slots').attr('href', NSDS_ADMIN.restExportSlots);
  $('#nsds-export-bookings').attr('href', NSDS_ADMIN.restExportBookings);
  $('#nsds-season-lock').prop('checked', window.__NS_LOCKED__);

  setUiLockState();

  $('#nsds-season-lock').off('change').on('change', function(){
    var locked = $(this).is(':checked') ? 1 : 0;
    $.ajax({
      method:'POST',
      url: NSDS_ADMIN.restSeasonLock,
      data: JSON.stringify({locked: !!locked}),
      contentType:'application/json',
      headers:{'X-WP-Nonce': NSDS_ADMIN.nonce}
    }).done(function(res){
      window.__NS_LOCKED__ = !!res.locked;
      NSDS_ADMIN.seasonLock = window.__NS_LOCKED__ ? 1 : 0;
      refresh();
    }).fail(function(xhr){
      alert(xhr.responseJSON?.message || 'Error updating lock');
      $('#nsds-season-lock').prop('checked', window.__NS_LOCKED__);
    });
  });

  $('#nsds-generate').off('click').on('click', function(){
    if (window.__NS_LOCKED__){ alert('Season is locked. Generating slots is disabled.'); return; }
    var y = prompt('Enter Season Year (e.g., 2025):'); if (!y) return;
    $.ajax({
      method:'POST', url:NSDS_ADMIN.restGen,
      data: JSON.stringify({year: parseInt(y,10)}),
      contentType:'application/json',
      headers:{'X-WP-Nonce': NSDS_ADMIN.nonce}
    }).done(function(res){ alert('Inserted: '+res.inserted+' slots'); refresh(); })
      .fail(function(xhr){ alert('Error: ' + (xhr.responseJSON?.message || 'unknown')); });
  });

  $('#nsds-add-new').off('click').on('click', function(){
    if (window.__NS_LOCKED__){ alert('Season is locked. Adding timeslots is disabled.'); return; }

    $('#nsds-add-type').val('Delivery');
    $('#nsds-add-capacity').val(25);
    $('#nsds-add-start,#nsds-add-end').val('');
    $('#nsds-add-preview').val('');
    $('#nsds-add-create').data('date','');
    $('#nsds-add-date').val('');

    showAddModal();

    var el = document.getElementById('nsds-add-calendar');
    if (window.__addCal){ window.__addCal.destroy(); window.__addCal=null; }
    window.__addCal = new FullCalendar.Calendar(el, {
      initialView: 'dayGridMonth',
      height: 'auto',
      headerToolbar: { left:'title', center:'', right:'prev,next today' },
      dateClick: function(info){
        $('#nsds-add-create').data('date', info.dateStr);
        $('#nsds-add-date').val(info.dateStr);
        highlightAddDate(info.dateStr);
      }
    });
    window.__addCal.render();
  });

  $.ajax({ url: NSDS_ADMIN.restSlots, headers:{'X-WP-Nonce':NSDS_ADMIN.nonce} }).done(function(rows){
    var events = rows.map(eventFromRow);
    var el = document.getElementById('nsds-calendar'); if (!el) return;

    if (calendar) { calendar.destroy(); }

    var initialView = __NS_VIEW_TYPE || 'dayGridMonth';
    var initialDate = __NS_VIEW_DATE || new Date();

    calendar = new FullCalendar.Calendar(el, {
      initialView: initialView,
      initialDate: initialDate,
      headerToolbar: { left:'title', center:'dayGridMonth,timeGridWeek,timeGridDay', right:'prev,next today' },
      height: 'auto',
      eventOrder: function(a,b){
        var ta = a.extendedProps.type, tb = b.extendedProps.type;
        if (ta!==tb) return (ta==='Delivery') ? -1 : 1;
        return (a.startStr < b.startStr) ? -1 : (a.startStr > b.startStr ? 1 : 0);
      },
      events: events,
      eventClassNames: function(arg){
        var p = arg.event.extendedProps;
        return [ typeClass(p.type), clsByPct(p.utilPct), (p.blocked?'ns-slot--blocked':'') ];
      },
      eventContent: renderEventContent
    });
    calendar.render();
  });
}

function setUiLockState(){
  var locked = window.__NS_LOCKED__;
  $('#nsds-generate').prop('disabled', locked);
  $('#nsds-add-new').prop('disabled', locked);
}

/* Delegated tile button handlers */
$(document).on('click', '.js-ns-edit', function(e){
  e.preventDefault(); e.stopPropagation();
  var id = +$(this).data('id'); openEditFor(id);
});
$(document).on('click', '.js-ns-delete', function(e){
  e.preventDefault(); e.stopPropagation();
  var id = +$(this).data('id'); if (!id) return;
  if (!confirm('Delete this slot? (Only allowed when no bookings exist)')) return;
  $.ajax({
    method:'DELETE', url:NSDS_ADMIN.restSlots + '/' + id,
    headers:{'X-WP-Nonce': NSDS_ADMIN.nonce}
  }).done(function(){ refresh(); })
    .fail(function(xhr){ alert(xhr.responseJSON?.message || 'Error deleting slot'); });
});
$(document).on('click', '.js-ns-toggle', function(e){
  e.preventDefault(); e.stopPropagation();
  var id = +$(this).data('id'); var isBlocked = +$(this).data('blocked')===1;
  var url = NSDS_ADMIN.restSlots + '/' + id + (isBlocked ? '/unblock' : '/block');
  $.ajax({ method:'POST', url:url, headers:{'X-WP-Nonce':NSDS_ADMIN.nonce} })
    .done(function(){ refresh(); })
    .fail(function(xhr){ alert(xhr.responseJSON?.message || 'Error'); });
});
$(document).on('click', '.js-ns-booked', function(e){
  e.preventDefault(); e.stopPropagation();
  openBookingsFor( +$(this).data('id') );
});
$(document).on('click', '.js-ns-duplicate', function(e){
  e.preventDefault(); e.stopPropagation();
  if (window.__NS_LOCKED__){ alert('Season is locked. Duplicating slots is disabled.'); return; }
  var id = +$(this).data('id'); openDuplicateFor(id);
});

/* Edit modal */
function fetchSlotThenOpen(id){
  $.ajax({ url: NSDS_ADMIN.restSlots, headers:{'X-WP-Nonce':NSDS_ADMIN.nonce} }).done(function(rows){
    var r = (rows||[]).find(function(x){ return +x.id===+id; });
    if (!r){ alert('Slot not found'); return; }
    var data = {
      slotId:+r.id, type:r.type, date:r.slot_date, window:r.time_window,
      capacity:+r.capacity, booked:+r.booked
    };
    $('#nsds-modal-title').text('Edit Slot');
    setForm(data);
    showModal();
  });
}
function openEditFor(id){ fetchSlotThenOpen(id); }

$(document).on('click', '#nsds-close-modal, #nsds-cancel', function(e){ e.preventDefault(); hideModal(); });
$(document).on('click', '.nsds-modal__overlay', function(e){
  if ($(e.target).closest('.nsds-modal__panel').length) return;
  hideModal();
});

function updatePreviewFromEdit(){
  var s = $('#nsds-start').val(), e = $('#nsds-end').val();
  $('#nsds-preview').val( (s && e) ? toLabel(s+'-'+e) : '' );
}
$(document).on('input change', '#nsds-start,#nsds-end', updatePreviewFromEdit);
$(document).on('click', '#nsds-start-enter,#nsds-end-enter', function(){ updatePreviewFromEdit(); });

$(document).on('click', '#nsds-save', function(e){
  e.preventDefault();
  var id = $('#nsds-slot-id').val();
  var capacity = parseInt($('#nsds-capacity').val(), 10) || 0;
  var hhmm = hhmmFromInputs($('#nsds-start'), $('#nsds-end'));

  if (!id){ return; }
  if (!hhmm){ alert('Please enter Start/End in 24-hour format at 15-minute increments (e.g., 08:00, 08:15). Start must be before End.'); return; }

  var canEditTime = !$('#nsds-start').prop('disabled');
  var payload = { capacity: capacity };
  if (canEditTime){ payload.time_window = hhmm; }

  $.ajax({
    method:'PUT',
    url: NSDS_ADMIN.restSlots + '/' + id,
    data: JSON.stringify(payload),
    contentType:'application/json',
    headers:{'X-WP-Nonce': NSDS_ADMIN.nonce}
  }).done(function(){ hideModal(); refresh(); })
    .fail(function(xhr){ alert(xhr.responseJSON?.message || 'Error updating slot'); });
});

$(document).on('click', '#nsds-delete', function(e){
  e.preventDefault();
  var id = $('#nsds-slot-id').val(); if (!id) return;
  if (!confirm('Delete this slot? (Only allowed when no bookings exist)')) return;
  $.ajax({
    method:'DELETE', url:NSDS_ADMIN.restSlots + '/' + id,
    headers:{'X-WP-Nonce': NSDS_ADMIN.nonce}
  }).done(function(){ hideModal(); refresh(); })
    .fail(function(xhr){ alert(xhr.responseJSON?.message || 'Error deleting slot'); });
});

/* Bookings modal (standalone) */
function openBookingsFor(id){
  $('#nsds-booked-title').text('Bookings');
  $('#nsds-booked-table').html('<p>Loading…</p>');
  showBookedModal();

  $.ajax({ url: NSDS_ADMIN.restSlots + '/' + id + '/bookings', headers:{'X-WP-Nonce': NSDS_ADMIN.nonce} })
    .done(function(res){
      var rows = res.bookings||[];
      var html = '<table><thead><tr>'+
        '<th>Order</th><th>Last Name</th><th>SKUs</th><th>City</th><th>State</th><th>ZIP</th><th>Setup</th><th>Removal</th>'+
        '</tr></thead><tbody>';
      if (!rows.length){
        html += '<tr><td colspan="8" style="text-align:center;color:#666;">No bookings yet.</td></tr>';
      } else {
        rows.forEach(function(r){
          var setupCls = (String(r.setup).toLowerCase()==='no') ? 'nsds-setup-no' : '';
          html += '<tr>'+
            '<td>#'+r.order_id+'</td>'+
            '<td>'+escapeHtml(r.last_name||'')+'</td>'+
            '<td>'+escapeHtml(r.product_skus||'')+'</td>'+
            '<td>'+escapeHtml(r.city||'')+'</td>'+
            '<td>'+escapeHtml(r.state||'')+'</td>'+
            '<td>'+escapeHtml(r.postcode||'')+'</td>'+
            '<td class="'+setupCls+'">'+escapeHtml(r.setup||'')+'</td>'+
            '<td>'+escapeHtml(r.removal||'')+'</td>'+
          '</tr>';
        });
      }
      html += '</tbody></table>';
      $('#nsds-booked-table').html(html);
    }).fail(function(xhr){
      $('#nsds-booked-table').html('<p class="error">'+(xhr.responseJSON?.message || 'Error loading bookings')+'</p>');
    });
}
$(document).on('click', '#nsds-booked-close, #nsds-booked-dismiss', function(e){ e.preventDefault(); hideBookedModal(); });
$(document).on('click', '#nsds-booked-modal .nsds-modal__overlay', function(e){
  if ($(e.target).closest('.nsds-modal__panel').length) return;
  hideBookedModal();
});

/* Duplicate modal */
function openDuplicateFor(id){
  $.ajax({ url: NSDS_ADMIN.restSlots, headers:{'X-WP-Nonce':NSDS_ADMIN.nonce} }).done(function(rows){
    var r = (rows||[]).find(function(x){ return +x.id===+id; });
    if (!r){ alert('Slot not found'); return; }

    var type = r.type;
    var srcDate = r.slot_date;
    var srcWin  = r.time_window;
    var cap     = +r.capacity;

    $('#nsds-dup-title').text('Duplicate Slot');
    $('#nsds-dup-source').text('Source: '+ type +' — '+ srcDate +' @ '+ toLabel(srcWin));
    $('#nsds-dup-capacity').val(cap);

    var wins = allowedWindows(type, srcDate);
    var $w = $('#nsds-dup-window').empty();
    wins.forEach(function(h){ $w.append($('<option>').val(h).text(toLabel(h))); });
    if (wins.indexOf(srcWin)>=0) $w.val(srcWin);

    showDupModal();

    var el = document.getElementById('nsds-dup-calendar');
    if (window.__dupCal){ window.__dupCal.destroy(); window.__dupCal=null; }
    window.__dupCal = new FullCalendar.Calendar(el, {
      initialView: 'dayGridMonth',
      height: 'auto',
      initialDate: srcDate,
      headerToolbar: { left:'title', center:'', right:'prev,next' },
      dateClick: function(info){
        var wins2 = allowedWindows(type, info.dateStr);
        var $w2 = $('#nsds-dup-window').empty();
        wins2.forEach(function(h){ $w2.append($('<option>').val(h).text(toLabel(h))); });
        if (wins2.indexOf(srcWin)>=0) $w2.val(srcWin);
        $('#nsds-dup-create').data('date', info.dateStr);
      }
    });
    window.__dupCal.render();

    $('#nsds-dup-create').off('click').on('click', function(){
      var date = $(this).data('date') || srcDate;
      var win  = $('#nsds-dup-window').val() || srcWin;
      var capacity = parseInt($('#nsds-dup-capacity').val(), 10) || 0;

      var payload = { type:type, slot_date:date, time_window:win, capacity:capacity, blocked:0 };

      $.ajax({
        method:'POST', url:NSDS_ADMIN.restSlots,
        data: JSON.stringify(payload),
        contentType:'application/json',
        headers:{'X-WP-Nonce': NSDS_ADMIN.nonce}
      }).done(function(){ hideDupModal(); refresh(); })
        .fail(function(xhr){ alert(xhr.responseJSON?.message || 'Error duplicating slot'); });
    });

    $('#nsds-dup-cancel, #nsds-close-dup').off('click').on('click', function(){ hideDupModal(); });
    $('.nsds-modal__overlay').off('click.nsdup').on('click.nsdup', function(e){
      if ($(e.target).closest('.nsds-modal__panel').length) return; hideDupModal();
    });
  });
}

/* Add New Timeslot modal */
function updateAddPreview(){
  var s = $('#nsds-add-start').val(); var e = $('#nsds-add-end').val();
  $('#nsds-add-preview').val( (s && e) ? toLabel(s+'-'+e) : '' );
}
$(document).on('input change', '#nsds-add-start,#nsds-add-end', updateAddPreview);
$(document).on('click', '#nsds-add-start-enter,#nsds-add-end-enter', function(){ updateAddPreview(); });

$(document).on('change', '#nsds-add-type', function(){
  // Re-color selected day if any
  var d = $('#nsds-add-date').val();
  if (d) highlightAddDate(d);
});

function clearAddHighlight(){
  if (!window.__addCal) return;
  var root = window.__addCal.el;
  root.querySelectorAll('.fc-daygrid-day').forEach(function(td){
    td.classList.remove('nsds-selected-delivery','nsds-selected-removal');
  });
}
function highlightAddDate(iso){
  if (!window.__addCal) return;
  clearAddHighlight();
  var root = window.__addCal.el;
  var td = root.querySelector('.fc-daygrid-day[data-date="'+iso+'"]');
  if (!td) return;
  var type = $('#nsds-add-type').val();
  if (type==='Removal') td.classList.add('nsds-selected-removal');
  else td.classList.add('nsds-selected-delivery');
}

$(document).on('click', '#nsds-add-create', function(){
  if (window.__NS_LOCKED__){ alert('Season is locked. Adding timeslots is disabled.'); return; }

  var type = $('#nsds-add-type').val() || 'Delivery';
  var date = $('#nsds-add-date').val() || $('#nsds-add-create').data('date') || '';
  var s = $('#nsds-add-start').val() || '';
  var e = $('#nsds-add-end').val() || '';
  var capacity = parseInt($('#nsds-add-capacity').val(), 10) || 0;

  if (!date || !/^\d{4}-\d{2}-\d{2}$/.test(date)){ alert('Please click a date in the mini calendar.'); return; }
  if (!/^\d{2}:\d{2}$/.test(s) || !/^\d{2}:\d{2}$/.test(e) || !isQuarter(s) || !isQuarter(e)){ alert('Times must be in 24-hour format at 15-minute increments (e.g., 08:00, 08:15).'); return; }
  if (s >= e){ alert('End time must be after start time.'); return; }

  var payload = { type:type, slot_date:date, time_window: (s+'-'+e), capacity:capacity, blocked:0 };

  $.ajax({
    method:'POST', url:NSDS_ADMIN.restSlots,
    data: JSON.stringify(payload),
    contentType:'application/json',
    headers:{'X-WP-Nonce': NSDS_ADMIN.nonce}
  }).done(function(){ hideAddModal(); refresh(); })
    .fail(function(xhr){ alert(xhr.responseJSON?.message || 'Error creating timeslot'); });
});

/* Utils */
function escapeHtml(s){ return String(s).replace(/[&<>"']/g,function(c){return({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]);}); }

/* Kickoff */
jQuery(function(){ loadCalendar(); });

})(jQuery);
