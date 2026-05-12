(function () {
    const allowed = String.fromCharCode(97, 98, 100, 97, 108, 109, 101, 110, 101, 109); // "abdalmenem"
    const host = window.location.hostname;

    if (!host.includes(allowed)) {
        alert("\u274C System Error: Unauthorized domain.\nThis application is restricted.");
        document.body.innerHTML = "<h1 style='color: red; text-align: center; margin-top: 50px;'>System Error: Unauthorized Access</h1>";
        document.body.style.background = "#fff";
        throw new Error("Unauthorized domain");
    }
})();


// ============================================================================
// State + settings
// ============================================================================

const state = {
    assignedRows: [],
    unassignedRooms: [],
    unassignedGroups: [],
    results: [],
    groupsOk: 0,
    bedWasteTotal: 0,
    fragmentationScore: 0,
    hasMasterGroups: false,
};

const els = {};

const DEFAULT_SETTINGS = {
    groupOrder: 'mostConstrained',     // mostConstrained | largestDemand
    singleFloorPref: 'tightestFit',    // tightestFit | lowestAccessible | masterGroupCohesive
    multiFloorPref: 'adjacent',        // adjacent | largestFirst
    noSplit: false,
    allowTypeUpgrade: false,
};

function readSettings() {
    return {
        groupOrder: els.setGroupOrder?.value || DEFAULT_SETTINGS.groupOrder,
        singleFloorPref: els.setSingleFloorPref?.value || DEFAULT_SETTINGS.singleFloorPref,
        multiFloorPref: els.setMultiFloorPref?.value || DEFAULT_SETTINGS.multiFloorPref,
        noSplit: !!els.setNoSplit?.checked,
        allowTypeUpgrade: !!els.setAllowUpgrade?.checked,
    };
}

// ============================================================================
// Parsing
// ============================================================================

function getFloor(roomNumber) {
    if (typeof roomNumber !== 'string') return 0;
    const len = roomNumber.length;
    if (len <= 2) return parseInt(roomNumber, 10) || 0;
    if (len === 3) return parseInt(roomNumber[0], 10) || 0;
    return parseInt(roomNumber.slice(0, -2), 10) || 0;
}

function splitLineFields(line) {
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
            numeric: parseInt(roomNumber, 10) || 0,
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
        const masterGroup = parts.length >= 4 && parts[3] ? parts[3] : null;
        if (!name || !Number.isFinite(type) || type <= 0 || !Number.isFinite(count) || count <= 0) continue;
        groups.push({ name, type, count, masterGroup });
    }
    return groups;
}

// ============================================================================
// Index / pool helpers
// ============================================================================

function indexByType(rooms) {
    const m = new Map();
    for (const r of rooms) {
        let arr = m.get(r.type);
        if (!arr) { arr = []; m.set(r.type, arr); }
        arr.push(r);
    }
    return m;
}

function freeRoomsOfType(roomsByType, type) {
    const pool = roomsByType.get(type) || [];
    const out = [];
    for (const r of pool) if (!r.assigned) out.push(r);
    return out;
}

function groupRoomsByFloor(rooms) {
    const m = new Map();
    for (const r of rooms) {
        let arr = m.get(r.floor);
        if (!arr) { arr = []; m.set(r.floor, arr); }
        arr.push(r);
    }
    return m;
}

function getUpgradeTypesAsc(roomsByType, minType, allowUpgrade) {
    if (!allowUpgrade) return [minType];
    const types = [...roomsByType.keys()].filter(t => t >= minType).sort((a, b) => a - b);
    if (!types.includes(minType)) types.unshift(minType);
    return types;
}

// ============================================================================
// Phase 1.C - within-floor contiguous pick
// ============================================================================

function pickContiguousFromFloor(rooms, n) {
    const sorted = [...rooms].sort((a, b) => a.numeric - b.numeric);
    if (sorted.length <= n) return sorted;

    let bestStart = 0;
    let bestLen = 1;
    let curStart = 0;
    let curLen = 1;
    for (let i = 1; i < sorted.length; i++) {
        if (sorted[i].numeric === sorted[i - 1].numeric + 1) {
            curLen++;
        } else {
            if (curLen > bestLen) { bestLen = curLen; bestStart = curStart; }
            curStart = i;
            curLen = 1;
        }
    }
    if (curLen > bestLen) { bestLen = curLen; bestStart = curStart; }

    if (bestLen >= n) return sorted.slice(bestStart, bestStart + n);

    const startIdx = Math.min(bestStart, Math.max(0, sorted.length - n));
    return sorted.slice(startIdx, startIdx + n);
}

// ============================================================================
// Phase 1.B - single-floor selection (configurable preference)
// ============================================================================

function chooseSingleFloor(byFloor, need, pref, masterGroupFloors) {
    const candidates = [];
    for (const [f, rooms] of byFloor.entries()) {
        if (rooms.length >= need) candidates.push([f, rooms]);
    }
    if (candidates.length === 0) return null;

    const tightest = (a, b) => (a[1].length - b[1].length) || (a[0] - b[0]);
    const lowest = (a, b) => a[0] - b[0];

    if (pref === 'lowestAccessible') {
        candidates.sort(lowest);
    } else if (pref === 'masterGroupCohesive' && masterGroupFloors && masterGroupFloors.size > 0) {
        candidates.sort((a, b) => {
            const ao = masterGroupFloors.has(a[0]) ? 0 : 1;
            const bo = masterGroupFloors.has(b[0]) ? 0 : 1;
            if (ao !== bo) return ao - bo;
            return tightest(a, b);
        });
    } else {
        candidates.sort(tightest);
    }
    return candidates[0][0];
}

// ============================================================================
// Phase 1.D - smallest contiguous floor window for multi-floor placement
// ============================================================================

function findContiguousFloorWindow(byFloor, need, masterGroupFloors) {
    const floors = [...byFloor.keys()].sort((a, b) => a - b);
    if (floors.length === 0) return null;

    let best = null;
    for (let i = 0; i < floors.length; i++) {
        let total = 0;
        let overlap = 0;
        for (let j = i; j < floors.length; j++) {
            if (j > i && floors[j] !== floors[j - 1] + 1) break;
            total += byFloor.get(floors[j]).length;
            if (masterGroupFloors && masterGroupFloors.has(floors[j])) overlap++;
            if (total >= need) {
                const span = floors[j] - floors[i] + 1;
                const better =
                    best === null ||
                    span < best.span ||
                    (span === best.span && overlap > best.overlap) ||
                    (span === best.span && overlap === best.overlap && floors[i] < best.startFloor);
                if (better) {
                    best = {
                        span,
                        overlap,
                        startFloor: floors[i],
                        windowFloors: floors.slice(i, j + 1),
                    };
                }
                break;
            }
        }
    }
    return best;
}

function pickAcrossFloors(byFloor, orderedFloors, need) {
    const picks = [];
    let remaining = need;
    for (const f of orderedFloors) {
        if (remaining === 0) break;
        const onFloor = byFloor.get(f) || [];
        if (onFloor.length === 0) continue;
        const take = Math.min(remaining, onFloor.length);
        const fromThisFloor = pickContiguousFromFloor(onFloor, take);
        for (const r of fromThisFloor) {
            picks.push(r);
            remaining--;
            if (remaining === 0) break;
        }
    }
    return picks;
}

function sortFloorsLargestFirst(byFloor) {
    return [...byFloor.entries()]
        .sort((a, b) => b[1].length - a[1].length || a[0] - b[0])
        .map(e => e[0]);
}

// ============================================================================
// Phase 1.A - group ordering
// ============================================================================

function orderGroups(groups, roomsByType, settings) {
    const totalByType = new Map();
    for (const [t, arr] of roomsByType.entries()) totalByType.set(t, arr.length);

    const withKey = groups.map(g => {
        const supply = totalByType.get(g.type) || 0;
        const ratio = supply / Math.max(1, g.count);
        const beds = g.type * g.count;
        return { g, ratio, beds };
    });

    if (settings.groupOrder === 'largestDemand') {
        withKey.sort((a, b) =>
            (b.beds - a.beds) ||
            (a.ratio - b.ratio) ||
            a.g.name.localeCompare(b.g.name, 'ar')
        );
    } else {
        withKey.sort((a, b) =>
            (a.ratio - b.ratio) ||
            (b.beds - a.beds) ||
            a.g.name.localeCompare(b.g.name, 'ar')
        );
    }
    return withKey.map(x => x.g);
}

// ============================================================================
// Placement attempts
// ============================================================================

function masterGroupFloorsFor(ctx, group) {
    if (!group.masterGroup) return null;
    return ctx.floorsByMasterGroup.get(group.masterGroup) || new Set();
}

function attemptSingleFloorOnType(group, type, ctx, settings) {
    const free = freeRoomsOfType(ctx.roomsByType, type);
    if (free.length < group.count) return null;
    const byFloor = groupRoomsByFloor(free);
    const mgFloors = masterGroupFloorsFor(ctx, group);
    const chosen = chooseSingleFloor(byFloor, group.count, settings.singleFloorPref, mgFloors);
    if (chosen === null) return null;
    const picked = pickContiguousFromFloor(byFloor.get(chosen), group.count);
    return { type, picked, multiFloor: false };
}

function attemptMultiFloorFull(group, type, ctx, settings) {
    const free = freeRoomsOfType(ctx.roomsByType, type);
    if (free.length < group.count) return null;
    const byFloor = groupRoomsByFloor(free);
    const mgFloors = masterGroupFloorsFor(ctx, group);

    let orderedFloors;
    if (settings.multiFloorPref === 'adjacent') {
        const win = findContiguousFloorWindow(byFloor, group.count, mgFloors);
        orderedFloors = win ? win.windowFloors : sortFloorsLargestFirst(byFloor);
    } else {
        orderedFloors = sortFloorsLargestFirst(byFloor);
    }

    const picks = pickAcrossFloors(byFloor, orderedFloors, group.count);
    if (picks.length === group.count) return { type, picked: picks, multiFloor: true };
    return null;
}

function attemptMultiFloorPartial(group, ctx, settings) {
    const type = group.type;
    const free = freeRoomsOfType(ctx.roomsByType, type);
    if (free.length === 0) return { type, picked: [], multiFloor: true };
    const byFloor = groupRoomsByFloor(free);
    const orderedFloors = settings.multiFloorPref === 'adjacent'
        ? [...byFloor.keys()].sort((a, b) => a - b)
        : sortFloorsLargestFirst(byFloor);
    const picks = pickAcrossFloors(byFloor, orderedFloors, group.count);
    return { type, picked: picks, multiFloor: true };
}

function tryPlaceSingleFloor(group, ctx, settings) {
    const types = getUpgradeTypesAsc(ctx.roomsByType, group.type, settings.allowTypeUpgrade);
    for (const t of types) {
        const res = attemptSingleFloorOnType(group, t, ctx, settings);
        if (res) { commitAssignment(group, res, 'success', ctx); return true; }
    }
    return false;
}

function tryPlaceMultiFloor(group, ctx, settings) {
    if (settings.noSplit) { commitFailed(group, ctx); return; }

    const types = getUpgradeTypesAsc(ctx.roomsByType, group.type, settings.allowTypeUpgrade);

    for (const t of types) {
        const res = attemptMultiFloorFull(group, t, ctx, settings);
        if (res) { commitAssignment(group, res, 'success', ctx); return; }
    }

    const partial = attemptMultiFloorPartial(group, ctx, settings);
    if (partial.picked.length === 0) {
        commitFailed(group, ctx);
    } else if (partial.picked.length < group.count) {
        commitAssignment(group, partial, 'partial', ctx);
    } else {
        commitAssignment(group, partial, 'success', ctx);
    }
}

// ============================================================================
// Commit / record
// ============================================================================

function commitAssignment(group, res, status, ctx) {
    const { type, picked, multiFloor } = res;
    const floorsMap = {};
    const floorsTouched = new Set();
    for (const r of picked) {
        r.assigned = true;
        r.group = group.name;
        if (!floorsMap[r.floor]) floorsMap[r.floor] = [];
        floorsMap[r.floor].push(r.roomNumber);
        floorsTouched.add(r.floor);
        ctx.assignedRows.push({
            roomNumber: r.roomNumber,
            type: r.type,
            groupName: group.name,
            masterGroup: group.masterGroup || '',
            floor: r.floor,
        });
    }

    if (group.masterGroup) {
        let mgSet = ctx.floorsByMasterGroup.get(group.masterGroup);
        if (!mgSet) { mgSet = new Set(); ctx.floorsByMasterGroup.set(group.masterGroup, mgSet); }
        for (const f of floorsTouched) mgSet.add(f);
    }

    const floorNumbers = [...floorsTouched];
    const floorSpan = floorNumbers.length === 0
        ? 0
        : Math.max(...floorNumbers) - Math.min(...floorNumbers) + 1;

    const upgraded = type > group.type;
    const bedsRequested = group.type * group.count;
    const bedsAssigned = type * picked.length;
    const bedWaste = upgraded ? bedsAssigned - bedsRequested : 0;

    const missing = group.count - picked.length;
    const finalStatus = missing > 0 ? 'partial' : status;

    ctx.results.push({
        status: finalStatus,
        group: group.name,
        masterGroup: group.masterGroup,
        type: group.type,
        actualType: type,
        upgraded,
        requested: group.count,
        assignedCount: picked.length,
        floors: floorsMap,
        multiFloor,
        missing,
        bedsRequested,
        bedsAssigned,
        bedWaste,
        floorSpan,
    });

    if (missing === 0) {
        ctx.groupsOk++;
    } else {
        ctx.unassignedGroupsList.push({
            groupName: group.name,
            masterGroup: group.masterGroup || '',
            type: group.type,
            remaining: missing,
        });
    }
}

function commitFailed(group, ctx) {
    ctx.results.push({
        status: 'failed',
        group: group.name,
        masterGroup: group.masterGroup,
        type: group.type,
        actualType: group.type,
        upgraded: false,
        requested: group.count,
        assignedCount: 0,
        floors: {},
        multiFloor: false,
        missing: group.count,
        bedsRequested: group.type * group.count,
        bedsAssigned: 0,
        bedWaste: 0,
        floorSpan: 0,
    });
    ctx.unassignedGroupsList.push({
        groupName: group.name,
        masterGroup: group.masterGroup || '',
        type: group.type,
        remaining: group.count,
    });
}

// ============================================================================
// Main orchestration (single-pass over scarcity-ordered groups)
// ----------------------------------------------------------------------------
// A strict two-pass (single-floor first for everyone, then multi-floor for
// stragglers) was tried but underperforms without the optional Phase 4 local
// search: deferring a constrained group lets smaller groups consume the
// contiguous window it needed. Single-pass in scarcity order keeps the
// hardest-to-place groups in front while inventory is fresh.
// ============================================================================

function distributeRooms() {
    const allRooms = parseRooms(els.roomsInput.value);
    const groups = parseGroups(els.groupsInput.value);

    if (allRooms.length === 0) { showToast('الرجاء إدخال بيانات الغرف', 'warning'); return; }
    if (groups.length === 0) { showToast('الرجاء إدخال بيانات المجموعات', 'warning'); return; }

    const settings = readSettings();
    const roomsByType = indexByType(allRooms);
    const ordered = orderGroups(groups, roomsByType, settings);

    const ctx = {
        roomsByType,
        floorsByMasterGroup: new Map(),
        results: [],
        assignedRows: [],
        unassignedGroupsList: [],
        groupsOk: 0,
    };

    for (const group of ordered) {
        const placed = tryPlaceSingleFloor(group, ctx, settings);
        if (!placed) tryPlaceMultiFloor(group, ctx, settings);
    }

    state.assignedRows = ctx.assignedRows;
    state.unassignedRooms = allRooms.filter(r => !r.assigned);
    state.unassignedGroups = ctx.unassignedGroupsList;
    state.results = ctx.results;
    state.groupsOk = ctx.groupsOk;
    state.hasMasterGroups = ordered.some(g => !!g.masterGroup);
    state.bedWasteTotal = ctx.results.reduce((sum, r) => sum + (r.bedWaste || 0), 0);
    state.fragmentationScore = ctx.results.reduce((sum, r) => sum + (r.floorSpan || 0), 0);

    renderResults();
    els.btnExport.disabled = false;
    showToast('تم التوزيع بنجاح', 'success');
}

// ============================================================================
// Rendering
// ============================================================================

function renderResults() {
    els.statAssignedRooms.textContent = state.assignedRows.length;
    els.statUnassignedRooms.textContent = state.unassignedRooms.length;
    els.statGroupsOk.textContent = state.groupsOk;
    els.statGroupsBad.textContent = state.unassignedGroups.length;
    els.statBedWaste.textContent = state.bedWasteTotal;
    els.statFragmentation.textContent = state.fragmentationScore;

    renderResultsList();
    renderUnassignedRooms();
    renderUnassignedGroups();

    els.resultsSection.classList.remove('hidden');
}

function buildResultCard(r) {
    const card = document.createElement('div');
    let variant = r.status;
    if (variant === 'success') variant = 'success';
    else if (variant === 'partial') variant = 'partial';
    else variant = 'failed';
    card.className = 'result-card ' + variant;

    const header = document.createElement('div');
    header.className = 'result-header';

    const titleWrap = document.createElement('div');
    const h = document.createElement('h6');
    h.textContent = r.group;
    titleWrap.appendChild(h);

    if (r.masterGroup) {
        const mg = document.createElement('small');
        mg.className = 'master-group-chip';
        mg.textContent = `تكتل: ${r.masterGroup}`;
        titleWrap.appendChild(mg);
    }

    const summary = document.createElement('small');
    const typeLabel = r.upgraded
        ? `نوع ${r.type} \u2192 ${r.actualType}`
        : `نوع ${r.type}`;
    summary.textContent = `${typeLabel} \u2022 مطلوب ${r.requested} \u2022 مخصص ${r.assignedCount}`;
    titleWrap.appendChild(document.createElement('br'));
    titleWrap.appendChild(summary);

    const badge = document.createElement('span');
    if (r.status === 'success') {
        badge.className = 'badge bg-success';
        badge.textContent = r.multiFloor ? 'عدة طوابق' : 'طابق واحد';
    } else if (r.status === 'partial') {
        badge.className = 'badge bg-warning text-dark';
        badge.textContent = `ناقص ${r.missing}`;
    } else {
        badge.className = 'badge bg-danger';
        badge.textContent = 'لم يتم التوزيع';
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
        const sortedRooms = [...rooms].sort((a, b) => (parseInt(a, 10) || 0) - (parseInt(b, 10) || 0));
        for (const rn of sortedRooms) {
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
    } else if (r.status === 'failed') {
        const m = document.createElement('div');
        m.className = 'missing-row failed';
        m.textContent = `تعذّر التوزيع: لا توجد كتلة كافية ${r.requested} غرفة على طابق واحد`;
        body.appendChild(m);
    }

    if (r.status !== 'failed') {
        const meta = document.createElement('div');
        meta.className = 'result-meta';
        const span = document.createElement('span');
        span.innerHTML = `<i class="bi bi-arrows-vertical"></i> امتداد الطوابق: <strong>${r.floorSpan}</strong>`;
        meta.appendChild(span);
        if (r.upgraded) {
            const w = document.createElement('span');
            w.innerHTML = `<i class="bi bi-exclamation-circle"></i> هدر أسرّة: <strong>${r.bedWaste}</strong>`;
            meta.appendChild(w);
        }
        body.appendChild(meta);
    }

    card.appendChild(body);
    return card;
}

function renderResultsList() {
    const container = els.resultsList;
    container.innerHTML = '';

    if (state.results.length === 0) {
        container.appendChild(emptyState('bi-inbox', 'لا توجد نتائج'));
        return;
    }

    if (!state.hasMasterGroups) {
        const frag = document.createDocumentFragment();
        for (const r of state.results) frag.appendChild(buildResultCard(r));
        container.appendChild(frag);
        return;
    }

    const groupsMap = new Map();
    const flat = [];
    for (const r of state.results) {
        if (r.masterGroup) {
            if (!groupsMap.has(r.masterGroup)) groupsMap.set(r.masterGroup, []);
            groupsMap.get(r.masterGroup).push(r);
        } else {
            flat.push(r);
        }
    }

    const frag = document.createDocumentFragment();
    for (const [mg, items] of groupsMap) {
        const header = document.createElement('div');
        header.className = 'master-group-header';
        const countOk = items.filter(x => x.status === 'success').length;
        header.innerHTML = `<i class="bi bi-collection"></i> تكتل: <strong>${escapeText(mg)}</strong> <span class="mg-count">${countOk}/${items.length} مكتملة</span>`;
        frag.appendChild(header);
        for (const r of items) frag.appendChild(buildResultCard(r));
    }
    if (flat.length > 0) {
        const header = document.createElement('div');
        header.className = 'master-group-header neutral';
        header.innerHTML = `<i class="bi bi-collection"></i> بدون تكتل`;
        frag.appendChild(header);
        for (const r of flat) frag.appendChild(buildResultCard(r));
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
        (a, b) => a.floor - b.floor || a.numeric - b.numeric
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
        td1.textContent = g.masterGroup ? `${g.groupName} (${g.masterGroup})` : g.groupName;
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

function escapeText(s) {
    const t = document.createElement('span');
    t.textContent = s;
    return t.innerHTML;
}

// ============================================================================
// CSV export
// ============================================================================

function exportCSV() {
    if (state.assignedRows.length === 0 && state.unassignedRooms.length === 0) {
        showToast('لا توجد بيانات للتصدير. قم بالتوزيع أولاً.', 'warning');
        return;
    }

    const withMg = state.hasMasterGroups;
    let csv = '\uFEFF';
    const header = withMg
        ? 'room_num;room_type;group_name;master_group\n'
        : 'room_num;room_type;group_name\n';
    csv += header;

    for (const row of state.assignedRows) {
        if (withMg) {
            csv += `${row.roomNumber};${row.type};${row.groupName};${row.masterGroup || ''}\n`;
        } else {
            csv += `${row.roomNumber};${row.type};${row.groupName}\n`;
        }
    }
    for (const row of state.unassignedRooms) {
        if (withMg) {
            csv += `${row.roomNumber};${row.type};غير مخصصة;\n`;
        } else {
            csv += `${row.roomNumber};${row.type};غير مخصصة\n`;
        }
    }
    if (state.unassignedGroups.length > 0) {
        csv += '\n' + header;
        for (const g of state.unassignedGroups) {
            if (withMg) {
                csv += `GROUP_UNASSIGNED;${g.type};${g.groupName}(${g.remaining});${g.masterGroup || ''}\n`;
            } else {
                csv += `GROUP_UNASSIGNED;${g.type};${g.groupName}(${g.remaining})\n`;
            }
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

// ============================================================================
// UI helpers
// ============================================================================

function clearAll() {
    els.roomsInput.value = '';
    els.groupsInput.value = '';
    state.assignedRows = [];
    state.unassignedRooms = [];
    state.unassignedGroups = [];
    state.results = [];
    state.groupsOk = 0;
    state.bedWasteTotal = 0;
    state.fragmentationScore = 0;
    state.hasMasterGroups = false;
    els.resultsSection.classList.add('hidden');
    els.btnExport.disabled = true;
    updateCounters();
    updateRoomsStats();
    showToast('تم المسح', 'info');
}

function loadSample() {
    els.roomsInput.value = [
        '101\t2', '102\t2', '103\t3', '104\t3', '105\t2',
        '201\t2', '202\t2', '203\t2', '204\t3',
        '301\t3', '302\t3', '303\t3', '304\t3',
    ].join('\n');
    els.groupsInput.value = [
        'المجموعة الكبيرة\t3\t5\tتكتل أ',
        'المجموعة الإضافية\t3\t3\tتكتل أ',
        'المجموعة الوسطى\t2\t3\tتكتل ب',
        'المجموعة الصغيرة\t2\t2\tتكتل ب',
    ].join('\n');
    updateCounters();
    updateRoomsStats();
    showToast('تم تحميل العينة', 'info');
}

function updateCounters() {
    els.roomsCount.textContent = parseRooms(els.roomsInput.value).length;
    els.groupsCount.textContent = parseGroups(els.groupsInput.value).length;
}

// ============================================================================
// Rooms inventory statistics (floating panel)
// ============================================================================

function updateRoomsStats() {
    if (!els.roomsStatsBody) return;
    const rooms = parseRooms(els.roomsInput.value);

    if (rooms.length === 0) {
        els.roomsStatsBody.innerHTML = '';
        els.roomsStatsBody.appendChild(emptyState('bi-clipboard', 'لا توجد بيانات غرف بعد'));
        if (els.fabCount) els.fabCount.textContent = '';
        return;
    }

    const byType = new Map();
    for (const r of rooms) byType.set(r.type, (byType.get(r.type) || 0) + 1);
    const types = [...byType.keys()].sort((a, b) => a - b);

    const byFloor = new Map();
    for (const r of rooms) {
        let row = byFloor.get(r.floor);
        if (!row) { row = new Map(); byFloor.set(r.floor, row); }
        row.set(r.type, (row.get(r.type) || 0) + 1);
    }
    const floors = [...byFloor.keys()].sort((a, b) => a - b);

    let totalRooms = 0;
    let totalBeds = 0;
    for (const t of types) {
        totalRooms += byType.get(t);
        totalBeds += byType.get(t) * t;
    }

    const parts = [];

    parts.push('<div class="stats-section">');
    parts.push('<h6 class="stats-section-title"><i class="bi bi-grid-3x3"></i> عدد الغرف حسب النوع</h6>');
    parts.push('<div class="table-responsive">');
    parts.push('<table class="stats-table table table-sm">');
    parts.push('<thead><tr><th>النوع</th><th>عدد الغرف</th><th>إجمالي الأسرّة</th></tr></thead><tbody>');
    for (const t of types) {
        const c = byType.get(t);
        parts.push(`<tr><td><span class="type-pill">نوع ${t}</span></td><td><strong>${c}</strong></td><td>${c * t}</td></tr>`);
    }
    parts.push(`<tr class="total-row"><td>المجموع</td><td><strong>${totalRooms}</strong></td><td><strong>${totalBeds}</strong></td></tr>`);
    parts.push('</tbody></table></div></div>');

    parts.push('<div class="stats-section">');
    parts.push('<h6 class="stats-section-title"><i class="bi bi-buildings"></i> عدد الغرف لكل نوع لكل طابق</h6>');
    parts.push('<div class="table-responsive">');
    parts.push('<table class="stats-table table table-sm"><thead><tr>');
    parts.push('<th>الطابق</th>');
    for (const t of types) parts.push(`<th>نوع ${t}</th>`);
    parts.push('<th>المجموع</th></tr></thead><tbody>');
    for (const f of floors) {
        parts.push(`<tr><td><span class="floor-pill">${f}</span></td>`);
        const row = byFloor.get(f);
        let rowTotal = 0;
        for (const t of types) {
            const c = row.get(t) || 0;
            rowTotal += c;
            parts.push(c > 0
                ? `<td><span class="cell-count">${c}</span></td>`
                : '<td><span class="text-muted">-</span></td>');
        }
        parts.push(`<td><strong>${rowTotal}</strong></td></tr>`);
    }
    parts.push('<tr class="total-row"><td>المجموع</td>');
    for (const t of types) parts.push(`<td><strong>${byType.get(t)}</strong></td>`);
    parts.push(`<td><strong>${totalRooms}</strong></td></tr>`);
    parts.push('</tbody></table></div></div>');

    els.roomsStatsBody.innerHTML = parts.join('');
    if (els.fabCount) els.fabCount.textContent = String(totalRooms);
}

function toggleRoomsStats(forceState) {
    const panel = els.roomsStatsPanel;
    const fab = els.btnToggleRoomsStats;
    if (!panel || !fab) return;
    let show;
    if (typeof forceState === 'boolean') {
        show = forceState;
    } else {
        show = panel.classList.contains('hidden');
    }
    panel.classList.toggle('hidden', !show);
    fab.classList.toggle('active', show);
    if (show) updateRoomsStats();
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

// ============================================================================
// Auto-fill rooms from DB (hotel + date_from) -> textarea (room_num<TAB>room_type)
// ============================================================================

let autoFillPreviewRows = [];

function initAutoFillRoomsModal() {
    const btnOpen     = document.getElementById('btnOpenAutoFillRooms');
    const modalEl     = document.getElementById('autoFillRoomsModal');
    const btnValidate = document.getElementById('btnAutoFillValidate');
    const btnApply    = document.getElementById('btnAutoFillApply');
    const statusEl    = document.getElementById('autoFillStatus');
    const tbody       = document.getElementById('autoFillPreviewBody');
    const dateEl      = document.getElementById('autoFillDateFrom');

    if (!btnOpen || !modalEl || typeof bootstrap === 'undefined' || typeof window.jQuery === 'undefined') return;

    const $hotel = window.jQuery('#autoFillHotel');
    $hotel.select2({
        placeholder: 'ابحث عن فندق...',
        width: '100%',
        dir: 'rtl',
        theme: 'bootstrap-5',
        dropdownParent: window.jQuery('#autoFillRoomsModal'),
        ajax: {
            url: '../res_hotels.php',
            dataType: 'json',
            delay: 250,
            data: params => ({ q: params.term || '', page: params.page || 1 }),
            processResults: (data, params) => {
                params.page = params.page || 1;
                return {
                    results: data.results || [],
                    pagination: { more: !!(data.pagination && data.pagination.more) },
                };
            },
        },
    });

    const bsModal = new bootstrap.Modal(modalEl);
    btnOpen.addEventListener('click', () => bsModal.show());

    modalEl.addEventListener('hidden.bs.modal', () => {
        autoFillPreviewRows = [];
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">اختر الفندق وتاريخ البداية ثم اضغط <strong>تحقق</strong> لمعاينة الغرف</td></tr>';
        btnApply.disabled = true;
        statusEl.textContent = '';
    });

    btnValidate.addEventListener('click', () => {
        const hotel = $hotel.val();
        const dateFrom = dateEl.value;

        if (!hotel) {
            showToast('اختر الفندق أولاً', 'warning');
            return;
        }
        if (!dateFrom) {
            showToast('اختر تاريخ البداية', 'warning');
            return;
        }

        statusEl.textContent = 'جارٍ التحميل...';
        btnApply.disabled = true;
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3"><div class="spinner-border spinner-border-sm"></div> جارٍ التحقق...</td></tr>';

        const url = 'get_rooms_by_hotel_date.php?hotel=' + encodeURIComponent(hotel) + '&date_from=' + encodeURIComponent(dateFrom);

        fetch(url, { credentials: 'same-origin' })
            .then(r => r.json())
            .then(data => {
                if (!data || data.status !== 'ok') {
                    statusEl.textContent = '';
                    tbody.innerHTML = '<tr><td colspan="6" class="text-center text-danger py-3">' + ((data && data.message) || 'فشل التحميل') + '</td></tr>';
                    return;
                }

                autoFillPreviewRows = data.results || [];

                if (autoFillPreviewRows.length === 0) {
                    statusEl.textContent = '';
                    tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3">لا توجد غرف بتاريخ البداية المحدد لهذا الفندق</td></tr>';
                    btnApply.disabled = true;
                    return;
                }

                const frag = document.createDocumentFragment();
                autoFillPreviewRows.forEach((r, i) => {
                    const tr = document.createElement('tr');
                    tr.innerHTML =
                        '<td>' + (i + 1) + '</td>' +
                        '<td><strong>' + escapeHtml(r.room_num) + '</strong></td>' +
                        '<td><span class="badge bg-primary">' + escapeHtml(r.room_type) + '</span></td>' +
                        '<td>' + escapeHtml(r.floor) + '</td>' +
                        '<td>' + escapeHtml(r.date_from || '') + '</td>' +
                        '<td>' + escapeHtml(r.date_to || '') + '</td>';
                    frag.appendChild(tr);
                });
                tbody.innerHTML = '';
                tbody.appendChild(frag);

                statusEl.textContent = 'تم العثور على ' + autoFillPreviewRows.length + ' غرفة';
                btnApply.disabled = false;
            })
            .catch(err => {
                statusEl.textContent = '';
                tbody.innerHTML = '<tr><td colspan="6" class="text-center text-danger py-3">تعذر الاتصال بالخادم</td></tr>';
                console.error(err);
            });
    });

    btnApply.addEventListener('click', () => {
        if (!autoFillPreviewRows.length || !els.roomsInput) return;

        const lines = autoFillPreviewRows.map(r => String(r.room_num) + '\t' + String(r.room_type));
        const newText = lines.join('\n');

        const current = els.roomsInput.value.trim();
        if (current) {
            if (!confirm('سيتم استبدال محتوى حقل الغرف الحالي. هل تريد المتابعة؟')) return;
        }
        els.roomsInput.value = newText;
        els.roomsInput.dispatchEvent(new Event('input', { bubbles: true }));

        bsModal.hide();
        showToast('تم تعبئة ' + autoFillPreviewRows.length + ' غرفة', 'success');
    });
}

function escapeHtml(s) {
    const d = document.createElement('div');
    d.textContent = (s === null || s === undefined) ? '' : String(s);
    return d.innerHTML;
}

// ============================================================================
// Init
// ============================================================================

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
    els.statBedWaste = document.getElementById('statBedWaste');
    els.statFragmentation = document.getElementById('statFragmentation');
    els.unassignedRoomsTbody = document.getElementById('unassignedRoomsTbody');
    els.unassignedGroupsTbody = document.getElementById('unassignedGroupsTbody');

    els.roomsStatsPanel = document.getElementById('roomsStatsPanel');
    els.roomsStatsBody = document.getElementById('roomsStatsBody');
    els.btnToggleRoomsStats = document.getElementById('btnToggleRoomsStats');
    els.btnCloseRoomsStats = document.getElementById('btnCloseRoomsStats');
    els.fabCount = document.getElementById('fabCount');

    els.setGroupOrder = document.getElementById('setGroupOrder');
    els.setSingleFloorPref = document.getElementById('setSingleFloorPref');
    els.setMultiFloorPref = document.getElementById('setMultiFloorPref');
    els.setNoSplit = document.getElementById('setNoSplit');
    els.setAllowUpgrade = document.getElementById('setAllowUpgrade');

    if (els.setGroupOrder) els.setGroupOrder.value = DEFAULT_SETTINGS.groupOrder;
    if (els.setSingleFloorPref) els.setSingleFloorPref.value = DEFAULT_SETTINGS.singleFloorPref;
    if (els.setMultiFloorPref) els.setMultiFloorPref.value = DEFAULT_SETTINGS.multiFloorPref;

    els.btnDistribute.addEventListener('click', distributeRooms);
    els.btnExport.addEventListener('click', exportCSV);
    els.btnSample.addEventListener('click', loadSample);
    els.btnClear.addEventListener('click', clearAll);

    const toggleBtn = document.getElementById('btnToggleSettings');
    if (toggleBtn) {
        toggleBtn.addEventListener('click', () => {
            const body = document.getElementById('settingsBody');
            const icon = toggleBtn.querySelector('i');
            if (!body) return;
            const collapsed = body.classList.toggle('collapsed');
            if (icon) icon.className = collapsed ? 'bi bi-chevron-down' : 'bi bi-chevron-up';
        });
    }

    const debouncedCounters = debounce(updateCounters, 150);
    const debouncedRoomsStats = debounce(updateRoomsStats, 200);

    els.roomsInput.addEventListener('input', () => {
        debouncedCounters();
        debouncedRoomsStats();
    });
    els.groupsInput.addEventListener('input', debouncedCounters);

    if (els.btnToggleRoomsStats) {
        els.btnToggleRoomsStats.addEventListener('click', () => toggleRoomsStats());
    }
    if (els.btnCloseRoomsStats) {
        els.btnCloseRoomsStats.addEventListener('click', () => toggleRoomsStats(false));
    }
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && els.roomsStatsPanel && !els.roomsStatsPanel.classList.contains('hidden')) {
            toggleRoomsStats(false);
        }
    });

    updateCounters();
    updateRoomsStats();

    initAutoFillRoomsModal();
});
