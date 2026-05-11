/*(function () {
    const allowed = String.fromCharCode(97, 98, 100, 97, 108, 109, 101, 110, 101, 109); // "abdalmenem"
    const host = window.location.hostname;

    if (!host.includes(allowed)) {
        alert("\u274C System Error: Unauthorized domain.\nThis application is restricted.");
        document.body.innerHTML = "<h1 style='color: red; text-align: center; margin-top: 50px;'>System Error: Unauthorized Access</h1>";
        document.body.style.background = "#fff";
        throw new Error("Unauthorized domain");
    }
})();
*/
const state = {
    assignedRows: [],
    unassignedRooms: [],
    unassignedGroups: [],
    results: [],
    groupsOk: 0,
};

const els = {};

function getFloor(roomNumber) {
    if (typeof roomNumber !== 'string') return 0;
    const len = roomNumber.length;
    if (len <= 2) return parseInt(roomNumber, 10) || 0;
    if (len === 3) return parseInt(roomNumber[0], 10) || 0;
    return parseInt(roomNumber.slice(0, -2), 10) || 0;
}

function splitLineFields(line) {
    // Accept TAB, semicolon, or comma as field separators (Excel paste = TAB)
    return line.split(/\t|;|,/).map(x => x.trim());
}

function parseRooms(data) {
    const rooms = [];
    const lines = data.split(/\r?\n/);
    for (const raw of lines) {
        const line = raw.trim();
        if (!line) continue;
        const parts = splitLineFields(line);
        if (parts.length < 2) continue;
        const roomNumber = parts[0];
        const type = parseInt(parts[1], 10);
        if (!roomNumber || !Number.isFinite(type) || type <= 0) continue;
        rooms.push({
            roomNumber,
            type,
            floor: getFloor(roomNumber),
            assigned: false,
            group: null,
        });
    }
    return rooms;
}

function parseGroups(data) {
    const groups = [];
    const lines = data.split(/\r?\n/);
    for (const raw of lines) {
        const line = raw.trim();
        if (!line) continue;
        const parts = splitLineFields(line);
        if (parts.length < 3) continue;
        const name = parts[0];
        const type = parseInt(parts[1], 10);
        const count = parseInt(parts[2], 10);
        if (!name || !Number.isFinite(type) || type <= 0 || !Number.isFinite(count) || count <= 0) continue;
        groups.push({ name, type, count });
    }
    return groups;
}

function distributeRooms() {
    const allRooms = parseRooms(els.roomsInput.value);
    const groups = parseGroups(els.groupsInput.value);

    if (allRooms.length === 0) {
        showToast('الرجاء إدخال بيانات الغرف', 'warning');
        return;
    }
    if (groups.length === 0) {
        showToast('الرجاء إدخال بيانات المجموعات', 'warning');
        return;
    }

    // Largest demand first
    groups.sort((a, b) => (b.type * b.count) - (a.type * a.count));

    // Pre-index rooms by type so we don't scan all rooms per group
    const roomsByType = new Map();
    for (const room of allRooms) {
        let bucket = roomsByType.get(room.type);
        if (!bucket) { bucket = []; roomsByType.set(room.type, bucket); }
        bucket.push(room);
    }

    const results = [];
    const assignedRows = [];
    const unassignedGroups = [];
    let groupsOk = 0;

    for (const group of groups) {
        const pool = roomsByType.get(group.type) || [];
        const available = pool.filter(r => !r.assigned);

        // Group available rooms by floor (Map preserves insertion order, but we sort later)
        const byFloor = new Map();
        for (const room of available) {
            let arr = byFloor.get(room.floor);
            if (!arr) { arr = []; byFloor.set(room.floor, arr); }
            arr.push(room);
        }

        let remaining = group.count;

        // 1) Try a single floor (lowest floor that fits — same intent as original)
        const sortedFloors = [...byFloor.keys()].sort((a, b) => a - b);
        let chosenFloor = null;
        for (const f of sortedFloors) {
            if (byFloor.get(f).length >= remaining) { chosenFloor = f; break; }
        }

        if (chosenFloor !== null) {
            const picked = byFloor.get(chosenFloor).slice(0, remaining);
            for (const r of picked) {
                r.assigned = true;
                r.group = group.name;
                assignedRows.push({ roomNumber: r.roomNumber, type: r.type, groupName: group.name, floor: r.floor });
            }
            results.push({
                status: 'success',
                group: group.name,
                type: group.type,
                requested: group.count,
                assignedCount: picked.length,
                floors: { [chosenFloor]: picked.map(r => r.roomNumber) },
                multiFloor: false,
            });
            groupsOk++;
            continue;
        }

        // 2) Spread across floors — largest floor first to consolidate
        const floorsByDesc = [...byFloor.entries()].sort((a, b) => b[1].length - a[1].length);
        const pickedAll = [];
        outer: for (const [, rooms] of floorsByDesc) {
            for (const room of rooms) {
                if (remaining === 0) break outer;
                room.assigned = true;
                room.group = group.name;
                pickedAll.push(room);
                assignedRows.push({ roomNumber: room.roomNumber, type: room.type, groupName: group.name, floor: room.floor });
                remaining--;
            }
        }

        const floorsMap = {};
        for (const r of pickedAll) {
            if (!floorsMap[r.floor]) floorsMap[r.floor] = [];
            floorsMap[r.floor].push(r.roomNumber);
        }

        if (remaining === 0) {
            results.push({
                status: 'success',
                group: group.name,
                type: group.type,
                requested: group.count,
                assignedCount: pickedAll.length,
                floors: floorsMap,
                multiFloor: true,
            });
            groupsOk++;
        } else {
            results.push({
                status: 'partial',
                group: group.name,
                type: group.type,
                requested: group.count,
                assignedCount: pickedAll.length,
                floors: floorsMap,
                multiFloor: true,
                missing: remaining,
            });
            unassignedGroups.push({ groupName: group.name, type: group.type, remaining });
        }
    }

    state.assignedRows = assignedRows;
    state.unassignedRooms = allRooms.filter(r => !r.assigned);
    state.unassignedGroups = unassignedGroups;
    state.results = results;
    state.groupsOk = groupsOk;

    renderResults();
    els.btnExport.disabled = false;
    showToast('تم التوزيع بنجاح', 'success');
}

function renderResults() {
    els.statAssignedRooms.textContent = state.assignedRows.length;
    els.statUnassignedRooms.textContent = state.unassignedRooms.length;
    els.statGroupsOk.textContent = state.groupsOk;
    els.statGroupsBad.textContent = state.unassignedGroups.length;

    renderResultsList();
    renderUnassignedRooms();
    renderUnassignedGroups();

    els.resultsSection.classList.remove('hidden');
}

function renderResultsList() {
    const container = els.resultsList;
    container.innerHTML = '';

    if (state.results.length === 0) {
        container.appendChild(emptyState('bi-inbox', 'لا توجد نتائج'));
        return;
    }

    const frag = document.createDocumentFragment();
    for (const r of state.results) {
        const card = document.createElement('div');
        card.className = 'result-card ' + (r.status === 'partial' ? 'partial' : 'success');

        const header = document.createElement('div');
        header.className = 'result-header';

        const titleWrap = document.createElement('div');
        const h = document.createElement('h6');
        h.textContent = r.group;
        const small = document.createElement('small');
        small.textContent = `نوع ${r.type} • مطلوب ${r.requested} • مخصص ${r.assignedCount}`;
        titleWrap.appendChild(h);
        titleWrap.appendChild(document.createElement('br'));
        titleWrap.appendChild(small);

        const badge = document.createElement('span');
        if (r.status === 'success') {
            badge.className = 'badge bg-success';
            badge.textContent = r.multiFloor ? 'عدة طوابق' : 'طابق واحد';
        } else {
            badge.className = 'badge bg-warning text-dark';
            badge.textContent = `ناقص ${r.missing}`;
        }

        header.appendChild(titleWrap);
        header.appendChild(badge);
        card.appendChild(header);

        const body = document.createElement('div');
        const floorEntries = Object.entries(r.floors).sort((a, b) => Number(a[0]) - Number(b[0]));
        for (const [floor, rooms] of floorEntries) {
            const row = document.createElement('div');
            row.className = 'floor-row';

            const fb = document.createElement('span');
            fb.className = 'floor-badge';
            fb.textContent = `طابق ${floor}`;

            const rc = document.createElement('span');
            rc.className = 'room-count';
            rc.textContent = `${rooms.length} غرفة`;

            const list = document.createElement('div');
            list.className = 'room-list';
            for (const rn of rooms) {
                const code = document.createElement('code');
                code.className = 'room-code';
                code.textContent = rn;
                list.appendChild(code);
            }

            row.appendChild(fb);
            row.appendChild(rc);
            row.appendChild(list);
            body.appendChild(row);
        }

        if (r.status === 'partial') {
            const m = document.createElement('div');
            m.className = 'missing-row';
            m.textContent = `يتبقى ${r.missing} غرفة من النوع ${r.type}`;
            body.appendChild(m);
        }

        card.appendChild(body);
        frag.appendChild(card);
    }
    container.appendChild(frag);
}

function renderUnassignedRooms() {
    const tbody = els.unassignedRoomsTbody;
    tbody.innerHTML = '';

    if (state.unassignedRooms.length === 0) {
        const tr = document.createElement('tr');
        const td = document.createElement('td');
        td.colSpan = 3;
        td.appendChild(emptyState('bi-check2-all', 'جميع الغرف مخصصة'));
        tr.appendChild(td);
        tbody.appendChild(tr);
        return;
    }

    const sorted = [...state.unassignedRooms].sort(
        (a, b) => a.floor - b.floor || a.roomNumber.localeCompare(b.roomNumber, undefined, { numeric: true })
    );

    const frag = document.createDocumentFragment();
    for (const r of sorted) {
        const tr = document.createElement('tr');
        const td1 = document.createElement('td');
        const code = document.createElement('code');
        code.textContent = r.roomNumber;
        td1.appendChild(code);
        const td2 = document.createElement('td');
        td2.textContent = r.type;
        const td3 = document.createElement('td');
        td3.textContent = r.floor;
        tr.appendChild(td1);
        tr.appendChild(td2);
        tr.appendChild(td3);
        frag.appendChild(tr);
    }
    tbody.appendChild(frag);
}

function renderUnassignedGroups() {
    const tbody = els.unassignedGroupsTbody;
    tbody.innerHTML = '';

    if (state.unassignedGroups.length === 0) {
        const tr = document.createElement('tr');
        const td = document.createElement('td');
        td.colSpan = 3;
        td.appendChild(emptyState('bi-emoji-smile', 'لا توجد مجموعات ناقصة'));
        tr.appendChild(td);
        tbody.appendChild(tr);
        return;
    }

    const frag = document.createDocumentFragment();
    for (const g of state.unassignedGroups) {
        const tr = document.createElement('tr');
        const td1 = document.createElement('td');
        td1.textContent = g.groupName;
        const td2 = document.createElement('td');
        td2.textContent = g.type;
        const td3 = document.createElement('td');
        const badge = document.createElement('span');
        badge.className = 'badge bg-warning text-dark';
        badge.textContent = g.remaining;
        td3.appendChild(badge);
        tr.appendChild(td1);
        tr.appendChild(td2);
        tr.appendChild(td3);
        frag.appendChild(tr);
    }
    tbody.appendChild(frag);
}

function emptyState(icon, text) {
    const div = document.createElement('div');
    div.className = 'empty-state';
    const i = document.createElement('i');
    i.className = 'bi ' + icon;
    const span = document.createElement('span');
    span.textContent = text;
    div.appendChild(i);
    div.appendChild(span);
    return div;
}

function exportCSV() {
    if (state.assignedRows.length === 0 && state.unassignedRooms.length === 0) {
        showToast('لا توجد بيانات للتصدير. قم بالتوزيع أولاً.', 'warning');
        return;
    }

    let csv = '\uFEFF'; // UTF-8 BOM for Arabic in Excel
    csv += 'room_num;room_type;group_name\n';
    for (const row of state.assignedRows) {
        csv += `${row.roomNumber};${row.type};${row.groupName}\n`;
    }
    for (const row of state.unassignedRooms) {
        csv += `${row.roomNumber};${row.type};غير مخصصة\n`;
    }
    if (state.unassignedGroups.length > 0) {
        csv += '\nroom_num;room_type;group_name\n';
        for (const g of state.unassignedGroups) {
            csv += `GROUP_UNASSIGNED;${g.type};${g.groupName}(${g.remaining})\n`;
        }
    }

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `توزيع_الغرف_${formatDateForFile(new Date())}.csv`;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
    showToast('تم تصدير الملف', 'success');
}

function clearAll() {
    els.roomsInput.value = '';
    els.groupsInput.value = '';
    state.assignedRows = [];
    state.unassignedRooms = [];
    state.unassignedGroups = [];
    state.results = [];
    state.groupsOk = 0;
    els.resultsSection.classList.add('hidden');
    els.btnExport.disabled = true;
    updateCounters();
    showToast('تم المسح', 'info');
}

function loadSample() {
    els.roomsInput.value = [
        '101\t2', '102\t2', '103\t3', '104\t3', '105\t2',
        '201\t2', '202\t2', '203\t2', '204\t3',
        '301\t3', '302\t3', '303\t3', '304\t3',
    ].join('\n');
    els.groupsInput.value = [
        'المجموعة الكبيرة\t3\t5',
        'المجموعة الإضافية\t3\t3',
        'المجموعة الوسطى\t2\t3',
        'المجموعة الصغيرة\t2\t2',
    ].join('\n');
    updateCounters();
    showToast('تم تحميل العينة', 'info');
}

function updateCounters() {
    els.roomsCount.textContent = parseRooms(els.roomsInput.value).length;
    els.groupsCount.textContent = parseGroups(els.groupsInput.value).length;
}

function debounce(fn, wait) {
    let t = null;
    return function () {
        clearTimeout(t);
        t = setTimeout(fn, wait);
    };
}

function formatDateForFile(d) {
    const pad = n => String(n).padStart(2, '0');
    return `${d.getFullYear()}${pad(d.getMonth() + 1)}${pad(d.getDate())}_${pad(d.getHours())}${pad(d.getMinutes())}`;
}

function showToast(message, type) {
    const container = document.getElementById('toastContainer');
    if (!container || typeof bootstrap === 'undefined') return;
    const bg = { success: 'bg-success', warning: 'bg-warning text-dark', danger: 'bg-danger', info: 'bg-primary' }[type] || 'bg-primary';
    const toast = document.createElement('div');
    toast.className = `toast align-items-center text-white ${bg} border-0`;
    toast.setAttribute('role', 'alert');
    toast.innerHTML = `
        <div class="d-flex">
            <div class="toast-body"></div>
            <button type="button" class="btn-close btn-close-white ms-auto me-2 m-auto" data-bs-dismiss="toast" aria-label="إغلاق"></button>
        </div>`;
    toast.querySelector('.toast-body').textContent = message;
    container.appendChild(toast);
    const t = new bootstrap.Toast(toast, { delay: 2500 });
    t.show();
    toast.addEventListener('hidden.bs.toast', () => toast.remove());
}

document.addEventListener('DOMContentLoaded', () => {
    els.roomsInput = document.getElementById('roomsInput');
    els.groupsInput = document.getElementById('groupsInput');
    els.roomsCount = document.getElementById('roomsCount');
    els.groupsCount = document.getElementById('groupsCount');
    els.btnDistribute = document.getElementById('btnDistribute');
    els.btnExport = document.getElementById('btnExport');
    els.btnSample = document.getElementById('btnSample');
    els.btnClear = document.getElementById('btnClear');
    els.resultsSection = document.getElementById('resultsSection');
    els.resultsList = document.getElementById('resultsList');
    els.statAssignedRooms = document.getElementById('statAssignedRooms');
    els.statUnassignedRooms = document.getElementById('statUnassignedRooms');
    els.statGroupsOk = document.getElementById('statGroupsOk');
    els.statGroupsBad = document.getElementById('statGroupsBad');
    els.unassignedRoomsTbody = document.getElementById('unassignedRoomsTbody');
    els.unassignedGroupsTbody = document.getElementById('unassignedGroupsTbody');

    els.btnDistribute.addEventListener('click', distributeRooms);
    els.btnExport.addEventListener('click', exportCSV);
    els.btnSample.addEventListener('click', loadSample);
    els.btnClear.addEventListener('click', clearAll);

    const debounced = debounce(updateCounters, 150);
    els.roomsInput.addEventListener('input', debounced);
    els.groupsInput.addEventListener('input', debounced);

    updateCounters();
});
