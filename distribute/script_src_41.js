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

// Default type-fallback rules. Each rule says: "1 demand unit of `from` can be
// satisfied by one of these bundles", where a bundle is `count` rooms of `type`.
// The primary bundle (1 room of `from`) is always implicit and tried first.
// Defaults mirror the original "any larger type with 1 room" upgrade behaviour;
// the user can add multi-room replacements (e.g. 2 rooms of type-2 for 1
// type-4 demand) via the UI.
const DEFAULT_FALLBACK_RULES = (function () {
    const rules = [];
    for (let from = 1; from <= 7; from++) {
        const to = [];
        for (let t = from + 1; t <= 8; t++) to.push({ type: t, count: 1 });
        rules.push({ from, to });
    }
    return rules;
})();

const FALLBACK_RULES_STORAGE_KEY_V1 = 'distribute:typeFallbackRules:v1';
const FALLBACK_RULES_STORAGE_KEY = 'distribute:typeFallbackRules:v2';

let fallbackRulesCache = null;
// True once we've successfully fetched rules from the server. Until then we
// fall back to localStorage / defaults so the page still works offline.
let fallbackRulesLoadedFromServer = false;
// True when the in-DOM rules differ from the last server-saved snapshot.
let fallbackRulesDirty = false;
// Last value confirmed saved to the server (JSON string for comparison).
let fallbackRulesServerSnapshot = null;

function cloneDefaultFallbackRules() {
    return DEFAULT_FALLBACK_RULES.map(r => ({
        from: r.from,
        to: r.to.map(b => ({ type: b.type, count: b.count })),
    }));
}

function normalizeRules(arr) {
    if (!Array.isArray(arr)) return [];
    return arr
        .map(r => {
            const from = parseInt(r.from, 10);
            if (!Number.isFinite(from) || from <= 0) return null;
            const to = [];
            if (Array.isArray(r.to)) {
                for (const item of r.to) {
                    // Accept both old (number) and new ({type,count}) formats.
                    let type, count;
                    if (typeof item === 'number' || typeof item === 'string') {
                        type = parseInt(item, 10);
                        count = 1;
                    } else if (item && typeof item === 'object') {
                        type = parseInt(item.type, 10);
                        count = parseInt(item.count, 10);
                        if (!Number.isFinite(count) || count <= 0) count = 1;
                    } else {
                        continue;
                    }
                    if (!Number.isFinite(type) || type <= 0) continue;
                    // Drop trivially redundant primary bundle (always implicit).
                    if (type === from && count === 1) continue;
                    to.push({ type, count });
                }
            }
            // Dedupe by (type,count)
            const seen = new Set();
            const dedup = [];
            for (const b of to) {
                const key = b.type + ':' + b.count;
                if (seen.has(key)) continue;
                seen.add(key);
                dedup.push(b);
            }
            return { from, to: dedup };
        })
        .filter(Boolean);
}

function loadFallbackRules() {
    if (fallbackRulesCache) return fallbackRulesCache;
    try {
        const rawV2 = localStorage.getItem(FALLBACK_RULES_STORAGE_KEY);
        if (rawV2) {
            const parsed = JSON.parse(rawV2);
            if (Array.isArray(parsed)) {
                fallbackRulesCache = normalizeRules(parsed);
                return fallbackRulesCache;
            }
        }
        // Migrate from v1 (numbers only) if present.
        const rawV1 = localStorage.getItem(FALLBACK_RULES_STORAGE_KEY_V1);
        if (rawV1) {
            const parsed = JSON.parse(rawV1);
            if (Array.isArray(parsed)) {
                const migrated = normalizeRules(parsed);
                fallbackRulesCache = migrated;
                try { localStorage.setItem(FALLBACK_RULES_STORAGE_KEY, JSON.stringify(migrated)); } catch (e) {}
                return fallbackRulesCache;
            }
        }
    } catch (e) { /* ignore corrupt storage */ }
    fallbackRulesCache = cloneDefaultFallbackRules();
    return fallbackRulesCache;
}

function saveFallbackRules(rules) {
    const normalized = normalizeRules(rules);
    fallbackRulesCache = normalized;
    try {
        localStorage.setItem(FALLBACK_RULES_STORAGE_KEY, JSON.stringify(normalized));
    } catch (e) { /* storage full / disabled - silently ignore */ }
}

function readSettings() {
    return {
        groupOrder: els.setGroupOrder?.value || DEFAULT_SETTINGS.groupOrder,
        singleFloorPref: els.setSingleFloorPref?.value || DEFAULT_SETTINGS.singleFloorPref,
        multiFloorPref: els.setMultiFloorPref?.value || DEFAULT_SETTINGS.multiFloorPref,
        noSplit: !!els.setNoSplit?.checked,
        allowTypeUpgrade: !!els.setAllowUpgrade?.checked,
        fallbackRules: loadFallbackRules(),
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

// Returns the ordered list of bundles a group may use to satisfy one demand
// unit. The first bundle is always the primary (1 room of the requested type);
// further bundles come from the user's fallback rules in the order specified.
function buildBundles(group, settings) {
    const primary = { type: group.type, count: 1, primary: true };
    if (!settings.allowTypeUpgrade) return [primary];

    const rules = Array.isArray(settings.fallbackRules) ? settings.fallbackRules : loadFallbackRules();
    const rule = rules.find(r => r.from === group.type);
    if (!rule || !Array.isArray(rule.to) || rule.to.length === 0) return [primary];

    const out = [primary];
    const seen = new Set([group.type + ':1']);
    for (const b of rule.to) {
        const type = parseInt(b.type, 10);
        const count = parseInt(b.count, 10) || 1;
        if (!Number.isFinite(type) || type <= 0 || count <= 0) continue;
        const key = type + ':' + count;
        if (seen.has(key)) continue;
        seen.add(key);
        out.push({ type, count, primary: false });
    }
    return out;
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

// ----------------------------------------------------------------------------
// Bundle-aware placement
// ----------------------------------------------------------------------------
// A "demand unit" is one row in the group's request (group.count units). A
// "bundle" is the rule { type, count } describing how many rooms of which type
// are needed to satisfy one unit. The primary bundle (1 room of group.type) is
// always tried first, so substitutions only happen when the primary fails.
// ----------------------------------------------------------------------------

function freeRoomsByFloorByType(roomsByType, allowedTypes) {
    // Returns Map<floor, Map<type, Room[]>> containing only free rooms of the
    // given types. Rooms inside each per-type array are NOT pre-sorted; the
    // pickContiguousFromFloor helper sorts them on demand.
    const map = new Map();
    const seen = new Set();
    for (const t of allowedTypes) {
        if (seen.has(t)) continue;
        seen.add(t);
        const pool = roomsByType.get(t);
        if (!pool) continue;
        for (const r of pool) {
            if (r.assigned) continue;
            let byT = map.get(r.floor);
            if (!byT) { byT = new Map(); map.set(r.floor, byT); }
            let arr = byT.get(t);
            if (!arr) { arr = []; byT.set(t, arr); }
            arr.push(r);
        }
    }
    return map;
}

function clonePoolsForFloors(floorByType, floors) {
    const out = new Map();
    for (const f of floors) {
        const src = floorByType.get(f);
        if (!src) continue;
        const inner = new Map();
        for (const [t, arr] of src.entries()) inner.set(t, arr.slice());
        out.set(f, inner);
    }
    return out;
}

function consumeBundleFromPool(pool, count) {
    // pool is an array of free rooms (single type). Returns null if pool can't
    // satisfy `count`, otherwise removes the chosen rooms from pool in-place
    // and returns them (a contiguous slice preferred).
    if (!pool || pool.length < count) return null;
    const sel = pickContiguousFromFloor(pool, count);
    const selSet = new Set(sel);
    // Mutate pool to remove selected.
    let w = 0;
    for (let i = 0; i < pool.length; i++) {
        if (!selSet.has(pool[i])) pool[w++] = pool[i];
    }
    pool.length = w;
    return sel;
}

function fillUnitsOnFloor(floorByTypePool, bundles, needUnits) {
    // Two-pass: first simulate to build a plan of (bundle per unit), then
    // consume rooms in bulk per type so picks stay contiguous when possible.
    // floorByTypePool is a Map<type, Room[]> for a single floor (mutated).
    const remaining = new Map();
    for (const [t, arr] of floorByTypePool.entries()) remaining.set(t, arr.length);

    const plan = [];
    for (let u = 0; u < needUnits; u++) {
        let chosen = null;
        for (const b of bundles) {
            if ((remaining.get(b.type) || 0) >= b.count) {
                remaining.set(b.type, remaining.get(b.type) - b.count);
                chosen = b;
                break;
            }
        }
        if (!chosen) break;
        plan.push(chosen);
    }

    // Aggregate per type, then bulk-pick contiguous slices.
    const totalsByType = new Map();
    for (const b of plan) totalsByType.set(b.type, (totalsByType.get(b.type) || 0) + b.count);

    const picked = [];
    for (const [t, total] of totalsByType.entries()) {
        const sel = consumeBundleFromPool(floorByTypePool.get(t), total);
        if (sel) for (const r of sel) picked.push(r);
    }
    return { picked, unitsDone: plan.length };
}

function fillUnitsAcrossFloors(pools, orderedFloors, bundles, needUnits) {
    // pools is Map<floor, Map<type, Room[]>> (mutated). Same two-pass approach
    // as fillUnitsOnFloor, extended to multiple floors: bundles try the first
    // preferred floor with stock; same-floor same-type picks are batched.
    const remaining = new Map(); // floor -> Map<type, count>
    for (const f of orderedFloors) {
        const byT = pools.get(f);
        if (!byT) continue;
        const c = new Map();
        for (const [t, arr] of byT.entries()) c.set(t, arr.length);
        remaining.set(f, c);
    }

    const plan = []; // Array of { floor, bundle }
    for (let u = 0; u < needUnits; u++) {
        let chosen = null;
        for (const b of bundles) {
            for (const f of orderedFloors) {
                const c = remaining.get(f);
                if (!c) continue;
                if ((c.get(b.type) || 0) >= b.count) {
                    c.set(b.type, c.get(b.type) - b.count);
                    chosen = { floor: f, bundle: b };
                    break;
                }
            }
            if (chosen) break;
        }
        if (!chosen) break;
        plan.push(chosen);
    }

    // Aggregate per (floor, type), then bulk-pick.
    const aggregated = new Map();
    for (const p of plan) {
        let agg = aggregated.get(p.floor);
        if (!agg) { agg = new Map(); aggregated.set(p.floor, agg); }
        agg.set(p.bundle.type, (agg.get(p.bundle.type) || 0) + p.bundle.count);
    }

    const picked = [];
    for (const [f, agg] of aggregated.entries()) {
        const byT = pools.get(f);
        if (!byT) continue;
        for (const [t, total] of agg.entries()) {
            const sel = consumeBundleFromPool(byT.get(t), total);
            if (sel) for (const r of sel) picked.push(r);
        }
    }
    return { picked, unitsDone: plan.length };
}

function totalRoomsOnFloor(floorByTypePool) {
    let n = 0;
    for (const arr of floorByTypePool.values()) n += arr.length;
    return n;
}

function sortFloorsByCapacityDesc(floorByType) {
    return [...floorByType.entries()]
        .map(([f, byT]) => [f, totalRoomsOnFloor(byT)])
        .sort((a, b) => b[1] - a[1] || a[0] - b[0])
        .map(e => e[0]);
}

function findContiguousFloorWindowForBundles(floorByType, bundles, need, masterGroupFloors) {
    // Smallest contiguous window of floors that can satisfy `need` demand
    // units using `bundles`. Ties broken by master-group overlap, then floor.
    const floors = [...floorByType.keys()].sort((a, b) => a - b);
    if (floors.length === 0) return null;

    let best = null;
    for (let i = 0; i < floors.length; i++) {
        for (let j = i; j < floors.length; j++) {
            if (j > i && floors[j] !== floors[j - 1] + 1) break;
            const windowFloors = floors.slice(i, j + 1);
            const pools = clonePoolsForFloors(floorByType, windowFloors);
            const { unitsDone } = fillUnitsAcrossFloors(pools, windowFloors, bundles, need);
            if (unitsDone >= need) {
                const span = floors[j] - floors[i] + 1;
                let overlap = 0;
                if (masterGroupFloors) {
                    for (const f of windowFloors) if (masterGroupFloors.has(f)) overlap++;
                }
                const better = best === null
                    || span < best.span
                    || (span === best.span && overlap > best.overlap)
                    || (span === best.span && overlap === best.overlap && floors[i] < best.startFloor);
                if (better) best = { span, overlap, startFloor: floors[i], windowFloors };
                break;
            }
        }
    }
    return best;
}

function attemptSingleFloorBundles(group, bundles, ctx, settings) {
    const allowedTypes = [...new Set(bundles.map(b => b.type))];
    const floorByType = freeRoomsByFloorByType(ctx.roomsByType, allowedTypes);
    if (floorByType.size === 0) return null;

    // Probe each floor to find ones with full capacity for this group.
    const candidates = [];
    for (const [floor, byT] of floorByType.entries()) {
        const probe = clonePoolsForFloors(floorByType, [floor]).get(floor);
        const { unitsDone } = fillUnitsOnFloor(probe, bundles, group.count);
        if (unitsDone >= group.count) {
            candidates.push({ floor, totalRooms: totalRoomsOnFloor(byT) });
        }
    }
    if (candidates.length === 0) return null;

    const tightest = (a, b) => (a.totalRooms - b.totalRooms) || (a.floor - b.floor);
    const lowest = (a, b) => a.floor - b.floor;
    const mgFloors = masterGroupFloorsFor(ctx, group);

    if (settings.singleFloorPref === 'lowestAccessible') {
        candidates.sort(lowest);
    } else if (settings.singleFloorPref === 'masterGroupCohesive' && mgFloors && mgFloors.size > 0) {
        candidates.sort((a, b) => {
            const ao = mgFloors.has(a.floor) ? 0 : 1;
            const bo = mgFloors.has(b.floor) ? 0 : 1;
            if (ao !== bo) return ao - bo;
            return tightest(a, b);
        });
    } else {
        candidates.sort(tightest);
    }

    const chosenFloor = candidates[0].floor;
    // Use the real pool so consumed rooms are tracked outside via assigned flag (set in commit).
    const realPool = new Map();
    for (const [t, arr] of floorByType.get(chosenFloor).entries()) realPool.set(t, arr.slice());
    const { picked, unitsDone } = fillUnitsOnFloor(realPool, bundles, group.count);
    if (unitsDone < group.count) return null;
    return { picked, unitsDone, multiFloor: false };
}

function attemptMultiFloorBundles(group, bundles, ctx, settings) {
    const allowedTypes = [...new Set(bundles.map(b => b.type))];
    const floorByType = freeRoomsByFloorByType(ctx.roomsByType, allowedTypes);
    if (floorByType.size === 0) return null;

    let orderedFloors;
    if (settings.multiFloorPref === 'adjacent') {
        const mgFloors = masterGroupFloorsFor(ctx, group);
        const win = findContiguousFloorWindowForBundles(floorByType, bundles, group.count, mgFloors);
        orderedFloors = win ? win.windowFloors : sortFloorsByCapacityDesc(floorByType);
    } else {
        orderedFloors = sortFloorsByCapacityDesc(floorByType);
    }

    const pools = clonePoolsForFloors(floorByType, orderedFloors);
    const { picked, unitsDone } = fillUnitsAcrossFloors(pools, orderedFloors, bundles, group.count);
    if (unitsDone < group.count) return null;
    return { picked, unitsDone, multiFloor: true };
}

function attemptPartialBundles(group, bundles, ctx, settings) {
    const allowedTypes = [...new Set(bundles.map(b => b.type))];
    const floorByType = freeRoomsByFloorByType(ctx.roomsByType, allowedTypes);
    if (floorByType.size === 0) return { picked: [], unitsDone: 0, multiFloor: true };

    const orderedFloors = settings.multiFloorPref === 'adjacent'
        ? [...floorByType.keys()].sort((a, b) => a - b)
        : sortFloorsByCapacityDesc(floorByType);

    const pools = clonePoolsForFloors(floorByType, orderedFloors);
    const { picked, unitsDone } = fillUnitsAcrossFloors(pools, orderedFloors, bundles, group.count);
    return { picked, unitsDone, multiFloor: true };
}

function tryPlaceSingleFloor(group, ctx, settings) {
    const bundles = buildBundles(group, settings);

    // Try primary-only first: this preserves the original "all rooms same as
    // requested type" preference, only resorting to substitutions when the
    // primary type alone can't satisfy the group on a single floor.
    if (bundles.length > 1) {
        const primary = [bundles[0]];
        const res = attemptSingleFloorBundles(group, primary, ctx, settings);
        if (res) { commitAssignment(group, res, 'success', ctx); return true; }
    }

    const withSubs = attemptSingleFloorBundles(group, bundles, ctx, settings);
    if (withSubs) { commitAssignment(group, withSubs, 'success', ctx); return true; }

    return false;
}

function tryPlaceMultiFloor(group, ctx, settings) {
    if (settings.noSplit) { commitFailed(group, ctx); return; }

    const bundles = buildBundles(group, settings);

    // Same staged approach across floors.
    if (bundles.length > 1) {
        const primary = [bundles[0]];
        const res = attemptMultiFloorBundles(group, primary, ctx, settings);
        if (res) { commitAssignment(group, res, 'success', ctx); return; }
    }

    const withSubs = attemptMultiFloorBundles(group, bundles, ctx, settings);
    if (withSubs) { commitAssignment(group, withSubs, 'success', ctx); return; }

    const partial = attemptPartialBundles(group, bundles, ctx, settings);
    if (partial.unitsDone === 0) {
        commitFailed(group, ctx);
    } else {
        commitAssignment(group, partial, 'partial', ctx);
    }
}

// ============================================================================
// Commit / record
// ============================================================================

function commitAssignment(group, res, status, ctx) {
    const { picked, multiFloor } = res;
    const unitsDone = Number.isFinite(res.unitsDone) ? res.unitsDone : picked.length;

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

    const bedsRequested = group.type * group.count;
    const bedsAssigned = picked.reduce((s, r) => s + (r.type || 0), 0);
    const upgraded = picked.some(r => r.type > group.type);
    const downsized = picked.some(r => r.type < group.type);
    const substituted = picked.some(r => r.type !== group.type);
    const maxPickedType = picked.reduce((m, r) => Math.max(m, r.type || 0), group.type);
    const bedWaste = Math.max(0, bedsAssigned - bedsRequested);

    // Bundle breakdown: count rooms per (type) for display.
    const typeBreakdown = {};
    for (const r of picked) typeBreakdown[r.type] = (typeBreakdown[r.type] || 0) + 1;

    const missing = Math.max(0, group.count - unitsDone);
    const finalStatus = missing > 0 ? 'partial' : status;

    ctx.results.push({
        status: finalStatus,
        group: group.name,
        masterGroup: group.masterGroup,
        type: group.type,
        actualType: upgraded ? maxPickedType : group.type,
        upgraded,
        downsized,
        substituted,
        requested: group.count,
        unitsDone,
        assignedCount: picked.length,
        floors: floorsMap,
        multiFloor,
        missing,
        bedsRequested,
        bedsAssigned,
        bedWaste,
        floorSpan,
        typeBreakdown,
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
        downsized: false,
        substituted: false,
        requested: group.count,
        unitsDone: 0,
        assignedCount: 0,
        floors: {},
        multiFloor: false,
        missing: group.count,
        bedsRequested: group.type * group.count,
        bedsAssigned: 0,
        bedWaste: 0,
        floorSpan: 0,
        typeBreakdown: {},
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
    let typeLabel;
    if (r.substituted) {
        // Show breakdown: "نوع 4 → 2×2, 1×4" means 2 rooms of type 2 and 1 of type 4.
        const parts = Object.entries(r.typeBreakdown || {})
            .map(([t, c]) => `${c}\u00d7${t}`)
            .join(', ');
        typeLabel = `نوع ${r.type} \u2192 ${parts}`;
    } else {
        typeLabel = `نوع ${r.type}`;
    }
    const unitsLabel = r.requested === r.unitsDone
        ? `وحدات ${r.unitsDone}`
        : `وحدات ${r.unitsDone}/${r.requested}`;
    summary.textContent = `${typeLabel} \u2022 ${unitsLabel} \u2022 غرف ${r.assignedCount}`;
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
        m.textContent = `يتبقى ${r.missing} وحدة من النوع ${r.type}`;
        body.appendChild(m);
    } else if (r.status === 'failed') {
        const m = document.createElement('div');
        m.className = 'missing-row failed';
        m.textContent = `تعذّر التوزيع: لا توجد كتلة كافية لـ ${r.requested} وحدة على طابق واحد`;
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

function fetchMasterGroupsFromDB(groupNames) {
    const unique = [...new Set(groupNames.filter(n => n && String(n).trim() !== ''))];
    if (unique.length === 0) return Promise.resolve({});

    return fetch('get_master_groups.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ groups: unique }),
    })
        .then(r => r.json())
        .then(data => {
            if (!data || data.status !== 'ok' || !data.map) return {};
            return data.map;
        })
        .catch(err => {
            console.warn('Failed to fetch master_groups from DB:', err);
            return {};
        });
}

function fetchRoomsByHotelDate(hotel, dateFrom) {
    if (!hotel || !dateFrom) return Promise.resolve([]);
    const url = 'get_rooms_by_hotel_date.php?hotel=' + encodeURIComponent(hotel)
        + '&date_from=' + encodeURIComponent(dateFrom);
    return fetch(url, { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => (data && data.status === 'ok' && Array.isArray(data.results)) ? data.results : [])
        .catch(err => {
            console.warn('Failed to fetch rooms for export:', err);
            return [];
        });
}

// CSV column order (always 6 columns):
// group_name; master_group; floor; room_num; date_from; date_to
function buildExportCSV(mgMap, roomMap) {
    const resolveMg = name => {
        if (!name) return '';
        if (Object.prototype.hasOwnProperty.call(mgMap, name)) return mgMap[name] || '';
        return '';
    };
    const roomInfo = num => {
        if (!num) return {};
        return roomMap[num] || roomMap[String(num)] || {};
    };

    let csv = '\uFEFF';
    const header = 'group_name;master_group;floor;room_num;date_from;date_to\n';
    csv += header;

    for (const row of state.assignedRows) {
        const mg = resolveMg(row.groupName) || row.masterGroup || '';
        const info = roomInfo(row.roomNumber);
        const floor = (info.floor !== undefined && info.floor !== null && info.floor !== '')
            ? info.floor
            : (row.floor !== undefined && row.floor !== null ? row.floor : '');
        csv += `${row.groupName};${mg};${floor};${row.roomNumber};${info.date_from || ''};${info.date_to || ''}\n`;
    }
    for (const row of state.unassignedRooms) {
        const info = roomInfo(row.roomNumber);
        const floor = (info.floor !== undefined && info.floor !== null && info.floor !== '')
            ? info.floor
            : (row.floor !== undefined && row.floor !== null ? row.floor : '');
        csv += `غير مخصصة;;${floor};${row.roomNumber};${info.date_from || ''};${info.date_to || ''}\n`;
    }
    if (state.unassignedGroups.length > 0) {
        csv += '\n' + header;
        for (const g of state.unassignedGroups) {
            const mg = resolveMg(g.groupName) || g.masterGroup || '';
            // No room data for unassigned groups; room-column placeholder marks them.
            csv += `${g.groupName}(${g.remaining});${mg};;GROUP_UNASSIGNED;;\n`;
        }
    }
    return csv;
}

function triggerCSVDownload(csv) {
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = `توزيع_الغرف_${formatDateForFile(new Date())}.csv`;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
}

function performExport({ hotel, dateFrom, skipRoomLookup }) {
    const wasDisabled = els.btnExport.disabled;
    els.btnExport.disabled = true;

    const groupNames = [];
    for (const row of state.assignedRows) if (row.groupName) groupNames.push(row.groupName);
    for (const g of state.unassignedGroups) if (g.groupName) groupNames.push(g.groupName);

    const mgPromise = fetchMasterGroupsFromDB(groupNames);
    const roomsPromise = skipRoomLookup
        ? Promise.resolve([])
        : fetchRoomsByHotelDate(hotel, dateFrom);

    return Promise.all([mgPromise, roomsPromise])
        .then(([mgMap, rooms]) => {
            const roomMap = {};
            for (const r of rooms) {
                if (r && r.room_num !== undefined && r.room_num !== null) {
                    roomMap[String(r.room_num)] = r;
                }
            }
            const csv = buildExportCSV(mgMap, roomMap);
            triggerCSVDownload(csv);
            const msg = skipRoomLookup
                ? 'تم تصدير الملف (بدون بيانات الفندق)'
                : 'تم تصدير الملف';
            showToast(msg, 'success');
            return true;
        })
        .catch(err => {
            console.error('Export failed:', err);
            showToast('فشل التصدير', 'danger');
            return false;
        })
        .finally(() => {
            els.btnExport.disabled = wasDisabled;
        });
}

function exportCSV() {
    if (state.assignedRows.length === 0 && state.unassignedRooms.length === 0) {
        showToast('لا توجد بيانات للتصدير. قم بالتوزيع أولاً.', 'warning');
        return;
    }
    openExportModal();
}

// ============================================================================
// Export modal (hotel + date_from picker + room preview)
// ============================================================================

let exportModalInitialized = false;
let exportPreviewRows = [];

function initExportModal() {
    if (exportModalInitialized) return;
    const modalEl = document.getElementById('exportRoomsModal');
    if (!modalEl || typeof bootstrap === 'undefined' || typeof window.jQuery === 'undefined') return;

    const $hotel = window.jQuery('#exportHotel');
    const dateEl = document.getElementById('exportDateFrom');
    const statusEl = document.getElementById('exportRoomsStatus');
    const tbody = document.getElementById('exportRoomsPreviewBody');
    const btnConfirm = document.getElementById('btnExportConfirm');
    const btnSkip = document.getElementById('btnExportSkip');

    $hotel.select2({
        placeholder: 'ابحث عن فندق...',
        width: '100%',
        dir: 'rtl',
        theme: 'bootstrap-5',
        dropdownParent: window.jQuery('#exportRoomsModal'),
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

    function resetDates(placeholder) {
        dateEl.innerHTML = '<option value="">' + escapeHtml(placeholder || 'اختر تاريخًا...') + '</option>';
        dateEl.disabled = true;
        btnConfirm.disabled = true;
        exportPreviewRows = [];
        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4">'
            + 'اختر الفندق وتاريخ البداية لمعاينة الغرف المرتبطة'
            + '</td></tr>';
        statusEl.textContent = '';
    }

    function loadRoomsPreview() {
        const hotel = $hotel.val();
        const date = dateEl.value;
        if (!hotel || !date) {
            btnConfirm.disabled = true;
            return;
        }
        statusEl.textContent = 'جارٍ تحميل الغرف...';
        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3"><div class="spinner-border spinner-border-sm"></div> جارٍ التحميل...</td></tr>';
        btnConfirm.disabled = true;

        fetchRoomsByHotelDate(hotel, date).then(rooms => {
            exportPreviewRows = rooms;
            if (rooms.length === 0) {
                statusEl.textContent = '';
                tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">'
                    + 'لا توجد غرف مطابقة'
                    + '</td></tr>';
                btnConfirm.disabled = true;
                return;
            }
            const frag = document.createDocumentFragment();
            rooms.forEach((r, i) => {
                const tr = document.createElement('tr');
                tr.innerHTML =
                    '<td>' + (i + 1) + '</td>'
                    + '<td><strong>' + escapeHtml(r.room_num) + '</strong></td>'
                    + '<td>' + escapeHtml(r.floor) + '</td>'
                    + '<td>' + escapeHtml(r.date_from || '') + '</td>'
                    + '<td>' + escapeHtml(r.date_to || '') + '</td>';
                frag.appendChild(tr);
            });
            tbody.innerHTML = '';
            tbody.appendChild(frag);
            statusEl.textContent = 'تم تحميل ' + rooms.length + ' غرفة. جاهز للتصدير.';
            btnConfirm.disabled = false;
        });
    }

    $hotel.on('change', () => {
        const hotel = $hotel.val();
        if (!hotel) { resetDates('اختر الفندق أولًا'); return; }

        statusEl.textContent = 'جارٍ تحميل التواريخ المتاحة...';
        dateEl.disabled = true;
        dateEl.innerHTML = '<option value="">جارٍ التحميل...</option>';
        btnConfirm.disabled = true;
        exportPreviewRows = [];
        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3"><div class="spinner-border spinner-border-sm"></div> جارٍ تحميل التواريخ...</td></tr>';

        fetch('get_hotel_dates.php?hotel=' + encodeURIComponent(hotel), { credentials: 'same-origin' })
            .then(r => r.json())
            .then(data => {
                if (!data || data.status !== 'ok' || !Array.isArray(data.results)) {
                    throw new Error((data && data.message) || 'فشل تحميل التواريخ');
                }
                if (data.results.length === 0) {
                    resetDates('لا توجد تواريخ متاحة لهذا الفندق');
                    statusEl.textContent = '';
                    tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">'
                        + 'لا توجد غرف مسجلة لهذا الفندق'
                        + '</td></tr>';
                    return;
                }
                const opts = ['<option value="">اختر تاريخًا...</option>'];
                for (const row of data.results) {
                    const v = row.date_from || '';
                    const c = row.room_count !== undefined ? (' (' + row.room_count + ' غرفة)') : '';
                    opts.push('<option value="' + escapeHtml(v) + '">' + escapeHtml(v) + c + '</option>');
                }
                dateEl.innerHTML = opts.join('');
                dateEl.disabled = false;
                statusEl.textContent = 'اختر تاريخًا لمعاينة الغرف';
                tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4">'
                    + 'اختر تاريخ البداية لمعاينة الغرف'
                    + '</td></tr>';
            })
            .catch(err => {
                console.warn(err);
                resetDates('فشل التحميل');
                statusEl.textContent = 'تعذر تحميل التواريخ';
            });
    });

    dateEl.addEventListener('change', loadRoomsPreview);

    btnConfirm.addEventListener('click', () => {
        const hotel = $hotel.val();
        const date = dateEl.value;
        if (!hotel || !date) {
            showToast('اختر الفندق وتاريخ البداية', 'warning');
            return;
        }
        const bsModal = bootstrap.Modal.getInstance(modalEl);
        performExport({ hotel, dateFrom: date, skipRoomLookup: false }).then(() => {
            if (bsModal) bsModal.hide();
        });
    });

    btnSkip.addEventListener('click', () => {
        const bsModal = bootstrap.Modal.getInstance(modalEl);
        performExport({ hotel: '', dateFrom: '', skipRoomLookup: true }).then(() => {
            if (bsModal) bsModal.hide();
        });
    });

    modalEl.addEventListener('hidden.bs.modal', () => {
        // Keep hotel selection across opens; only reset transient state.
        statusEl.textContent = '';
    });

    exportModalInitialized = true;
}

function openExportModal() {
    const modalEl = document.getElementById('exportRoomsModal');
    if (!modalEl || typeof bootstrap === 'undefined') {
        // Fallback: export with empty hotel data so the button still works.
        performExport({ hotel: '', dateFrom: '', skipRoomLookup: true });
        return;
    }
    initExportModal();
    const bsModal = bootstrap.Modal.getOrCreateInstance(modalEl);
    bsModal.show();
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

function enableTabInsertion(textarea) {
    if (!textarea) return;
    textarea.addEventListener('keydown', (e) => {
        if (e.key !== 'Tab' || e.ctrlKey || e.altKey || e.metaKey) return;
        e.preventDefault();

        const start = textarea.selectionStart;
        const end = textarea.selectionEnd;
        const value = textarea.value;

        if (e.shiftKey) {
            // Shift+Tab: remove a leading tab from the current line(s) if present.
            const lineStart = value.lastIndexOf('\n', start - 1) + 1;
            if (value[lineStart] === '\t') {
                textarea.value = value.slice(0, lineStart) + value.slice(lineStart + 1);
                const newStart = Math.max(lineStart, start - 1);
                const newEnd = Math.max(lineStart, end - 1);
                textarea.selectionStart = newStart;
                textarea.selectionEnd = newEnd;
                textarea.dispatchEvent(new Event('input', { bubbles: true }));
            }
            return;
        }

        // Insert a tab character at the caret (replacing any selection).
        textarea.value = value.slice(0, start) + '\t' + value.slice(end);
        textarea.selectionStart = textarea.selectionEnd = start + 1;
        textarea.dispatchEvent(new Event('input', { bubbles: true }));
    });
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
// Fallback rules repeater UI
// ============================================================================

function buildBundleNode(bundle) {
    const row = document.createElement('div');
    row.className = 'fr-bundle';

    const label1 = document.createElement('label');
    label1.textContent = 'بديل';
    row.appendChild(label1);

    const cnt = document.createElement('input');
    cnt.type = 'number';
    cnt.min = '1';
    cnt.step = '1';
    cnt.value = String(bundle.count);
    cnt.dataset.field = 'bundle-count';
    cnt.title = 'عدد الغرف لكل وحدة';
    row.appendChild(cnt);

    const times = document.createElement('span');
    times.className = 'fr-times';
    times.textContent = '\u00d7';
    row.appendChild(times);

    const label2 = document.createElement('label');
    label2.textContent = 'نوع';
    row.appendChild(label2);

    const typ = document.createElement('input');
    typ.type = 'number';
    typ.min = '1';
    typ.step = '1';
    typ.value = String(bundle.type);
    typ.dataset.field = 'bundle-type';
    typ.title = 'نوع الغرفة البديلة';
    row.appendChild(typ);

    const del = document.createElement('button');
    del.type = 'button';
    del.className = 'fr-del-bundle';
    del.dataset.action = 'delete-bundle';
    del.innerHTML = '<i class="bi bi-x-lg"></i> حذف';
    del.title = 'حذف البديل';
    row.appendChild(del);

    return row;
}

function buildRuleNode(rule, idx) {
    const card = document.createElement('div');
    card.className = 'fr-rule';
    card.dataset.index = String(idx);

    const head = document.createElement('div');
    head.className = 'fr-rule-head';

    const headLabel = document.createElement('label');
    headLabel.textContent = 'النوع المطلوب';
    head.appendChild(headLabel);

    const fromInput = document.createElement('input');
    fromInput.type = 'number';
    fromInput.min = '1';
    fromInput.step = '1';
    fromInput.value = String(rule.from);
    fromInput.dataset.field = 'from';
    head.appendChild(fromInput);

    const arrow = document.createElement('span');
    arrow.className = 'fr-rule-arrow';
    arrow.innerHTML = '<i class="bi bi-arrow-left"></i>';
    head.appendChild(arrow);

    const tag = document.createElement('span');
    tag.className = 'fr-rule-tag';
    tag.textContent = 'البدائل المسموحة';
    head.appendChild(tag);

    const delRule = document.createElement('button');
    delRule.type = 'button';
    delRule.className = 'fr-del-rule';
    delRule.dataset.action = 'delete-rule';
    delRule.innerHTML = '<i class="bi bi-trash"></i> حذف القاعدة';
    head.appendChild(delRule);

    card.appendChild(head);

    const bundles = document.createElement('div');
    bundles.className = 'fr-bundles';
    bundles.dataset.bundles = '';
    if (rule.to.length === 0) {
        const empty = document.createElement('div');
        empty.className = 'fr-bundles-empty';
        empty.textContent = 'لا توجد بدائل بعد. أضف بديلًا أو ستبقى الترقية معطلة لهذا النوع.';
        bundles.appendChild(empty);
    } else {
        for (const b of rule.to) bundles.appendChild(buildBundleNode(b));
    }
    card.appendChild(bundles);

    const addBtn = document.createElement('button');
    addBtn.type = 'button';
    addBtn.className = 'fr-add-bundle';
    addBtn.dataset.action = 'add-bundle';
    addBtn.innerHTML = '<i class="bi bi-plus-lg"></i> إضافة بديل';
    card.appendChild(addBtn);

    return card;
}

function renderFallbackRules() {
    const list = els.fallbackRulesList;
    if (!list) return;

    const rules = loadFallbackRules();
    list.innerHTML = '';

    if (rules.length === 0) {
        const empty = document.createElement('div');
        empty.className = 'fr-empty';
        empty.textContent = 'لا توجد قواعد. اضغط "إضافة قاعدة" لبدء التخصيص أو "الافتراضي" لاستعادة القيم الأصلية.';
        list.appendChild(empty);
        return;
    }

    const frag = document.createDocumentFragment();
    rules.forEach((rule, idx) => frag.appendChild(buildRuleNode(rule, idx)));
    list.appendChild(frag);
}

function collectFallbackRulesFromDOM() {
    const list = els.fallbackRulesList;
    if (!list) return loadFallbackRules();
    const ruleNodes = list.querySelectorAll('.fr-rule');
    const rules = [];
    ruleNodes.forEach(card => {
        const fromInput = card.querySelector('input[data-field="from"]');
        if (!fromInput) return;
        const fromVal = parseInt(fromInput.value, 10);
        if (!Number.isFinite(fromVal) || fromVal <= 0) return;

        const to = [];
        const bundleNodes = card.querySelectorAll('.fr-bundle');
        bundleNodes.forEach(bnode => {
            const cnt = parseInt(bnode.querySelector('input[data-field="bundle-count"]').value, 10);
            const typ = parseInt(bnode.querySelector('input[data-field="bundle-type"]').value, 10);
            if (!Number.isFinite(cnt) || cnt <= 0) return;
            if (!Number.isFinite(typ) || typ <= 0) return;
            // Drop self-mapping with count 1 (would be redundant with primary).
            if (typ === fromVal && cnt === 1) return;
            to.push({ type: typ, count: cnt });
        });

        rules.push({ from: fromVal, to });
    });
    return rules;
}

function persistFallbackRulesFromDOM() {
    saveFallbackRules(collectFallbackRulesFromDOM());
    markFallbackRulesDirty();
}

// ----------------------------------------------------------------------------
// Server sync: load / save the fallback rules so they are shared across users.
// localStorage still acts as a draft buffer if the user reloads the page
// before pressing "Save".
// ----------------------------------------------------------------------------

function rulesEqual(a, b) {
    try { return JSON.stringify(a) === JSON.stringify(b); }
    catch (e) { return false; }
}

function setFallbackRulesStatus(stateName, text, iconClass) {
    const el = els.fallbackRulesStatus;
    if (!el) return;
    el.className = 'fr-status ' + (stateName || '');
    const icon = iconClass ? `<i class="bi ${iconClass}"></i> ` : '';
    el.innerHTML = icon + escapeHtml(text);
}

function markFallbackRulesDirty() {
    if (!fallbackRulesLoadedFromServer) return;
    const current = collectFallbackRulesFromDOM();
    const dirty = !rulesEqual(current, JSON.parse(fallbackRulesServerSnapshot || '[]'));
    fallbackRulesDirty = dirty;
    if (els.btnSaveFallbackRules) els.btnSaveFallbackRules.disabled = !dirty;
    if (dirty) {
        setFallbackRulesStatus('dirty', 'تغييرات غير محفوظة', 'bi-exclamation-circle');
    } else {
        setFallbackRulesStatus('saved', 'لا توجد تغييرات', 'bi-check2-circle');
    }
}

function loadFallbackRulesFromServer() {
    setFallbackRulesStatus('loading', 'جارٍ تحميل القواعد من الخادم...', 'bi-cloud-arrow-down');
    if (els.btnSaveFallbackRules) els.btnSaveFallbackRules.disabled = true;

    return fetch('get_fallback_rules.php', { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            if (!data || data.status !== 'ok') {
                throw new Error(data && data.message ? data.message : 'فشل التحميل');
            }
            fallbackRulesLoadedFromServer = true;
            if (Array.isArray(data.rules)) {
                const serverRules = normalizeRules(data.rules);
                fallbackRulesCache = serverRules;
                try { localStorage.setItem(FALLBACK_RULES_STORAGE_KEY, JSON.stringify(serverRules)); } catch (e) {}
                fallbackRulesServerSnapshot = JSON.stringify(serverRules);
                fallbackRulesDirty = false;
                renderFallbackRules();
                const ts = data.updated_at ? ` (آخر تحديث: ${data.updated_at})` : '';
                setFallbackRulesStatus('saved', 'محفوظ على الخادم' + ts, 'bi-cloud-check');
                if (els.btnSaveFallbackRules) els.btnSaveFallbackRules.disabled = true;
            } else {
                // Server has nothing yet -> keep whatever we already have
                // locally (or defaults) and let the user push them up via Save.
                fallbackRulesServerSnapshot = JSON.stringify([]);
                fallbackRulesDirty = true;
                renderFallbackRules();
                setFallbackRulesStatus('dirty', 'لا توجد قواعد محفوظة على الخادم - اضغط "حفظ" لرفع القواعد الحالية', 'bi-cloud-slash');
                if (els.btnSaveFallbackRules) els.btnSaveFallbackRules.disabled = false;
            }
        })
        .catch(err => {
            console.warn('Failed to load fallback rules from server:', err);
            // Stay with whatever localStorage / defaults give us.
            renderFallbackRules();
            setFallbackRulesStatus('error', 'تعذّر التحميل من الخادم - يتم استخدام النسخة المحلية', 'bi-exclamation-triangle');
            // Treat the local snapshot as if it were the server's so the user
            // can save it explicitly to overwrite.
            fallbackRulesServerSnapshot = JSON.stringify(loadFallbackRules());
            fallbackRulesLoadedFromServer = true;
            if (els.btnSaveFallbackRules) els.btnSaveFallbackRules.disabled = false;
        });
}

function saveFallbackRulesToServer() {
    const rules = collectFallbackRulesFromDOM();
    setFallbackRulesStatus('saving', 'جارٍ الحفظ...', 'bi-cloud-arrow-up');
    if (els.btnSaveFallbackRules) els.btnSaveFallbackRules.disabled = true;

    return fetch('save_fallback_rules.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ rules }),
    })
        .then(r => r.json())
        .then(data => {
            if (!data || data.status !== 'ok') {
                throw new Error(data && data.message ? data.message : 'فشل الحفظ');
            }
            const saved = Array.isArray(data.rules) ? normalizeRules(data.rules) : rules;
            fallbackRulesCache = saved;
            try { localStorage.setItem(FALLBACK_RULES_STORAGE_KEY, JSON.stringify(saved)); } catch (e) {}
            fallbackRulesServerSnapshot = JSON.stringify(saved);
            fallbackRulesDirty = false;
            const ts = data.updated_at ? ` (${data.updated_at})` : '';
            setFallbackRulesStatus('saved', 'تم الحفظ على الخادم' + ts, 'bi-cloud-check');
            showToast('تم حفظ القواعد على الخادم', 'success');
        })
        .catch(err => {
            console.error('Failed to save fallback rules:', err);
            setFallbackRulesStatus('error', 'فشل الحفظ: ' + (err.message || ''), 'bi-exclamation-triangle');
            if (els.btnSaveFallbackRules) els.btnSaveFallbackRules.disabled = false;
            showToast('فشل الحفظ على الخادم', 'danger');
        });
}

function initFallbackRulesUI() {
    const list = els.fallbackRulesList;
    if (!list) return;

    // Collapse / expand. Collapsed by default (set via .collapsed class in PHP).
    if (els.fallbackRulesPanel) {
        const head = els.fallbackRulesPanel.querySelector('.fr-head');
        const togglePanel = () => {
            els.fallbackRulesPanel.classList.toggle('collapsed');
        };
        if (head) head.addEventListener('click', (e) => {
            // Only toggle when clicking the header itself (not inputs/buttons inside).
            if (e.target.closest('button') && !e.target.closest('#btnToggleFallbackRules')) return;
            togglePanel();
        });
    }

    if (els.btnSaveFallbackRules) {
        els.btnSaveFallbackRules.addEventListener('click', saveFallbackRulesToServer);
    }

    renderFallbackRules();
    // Kick off the server fetch. Render once with local fallback so the UI is
    // never empty, then re-render after the server responds.
    loadFallbackRulesFromServer();

    list.addEventListener('input', (e) => {
        const target = e.target;
        if (!target) return;
        if (target.matches('input[data-field="from"]')
            || target.matches('input[data-field="bundle-type"]')
            || target.matches('input[data-field="bundle-count"]')) {
            persistFallbackRulesFromDOM();
        }
    });

    list.addEventListener('click', (e) => {
        const delBundleBtn = e.target.closest('button[data-action="delete-bundle"]');
        if (delBundleBtn) {
            const bundleNode = delBundleBtn.closest('.fr-bundle');
            const bundlesWrap = delBundleBtn.closest('[data-bundles]');
            if (bundleNode) bundleNode.remove();
            // Re-show the empty hint if all bundles gone.
            if (bundlesWrap && bundlesWrap.querySelectorAll('.fr-bundle').length === 0) {
                const empty = document.createElement('div');
                empty.className = 'fr-bundles-empty';
                empty.textContent = 'لا توجد بدائل بعد. أضف بديلًا أو ستبقى الترقية معطلة لهذا النوع.';
                bundlesWrap.appendChild(empty);
            }
            persistFallbackRulesFromDOM();
            return;
        }

        const addBundleBtn = e.target.closest('button[data-action="add-bundle"]');
        if (addBundleBtn) {
            const card = addBundleBtn.closest('.fr-rule');
            if (!card) return;
            const bundlesWrap = card.querySelector('[data-bundles]');
            if (!bundlesWrap) return;
            // Remove empty hint if present.
            const emptyHint = bundlesWrap.querySelector('.fr-bundles-empty');
            if (emptyHint) emptyHint.remove();
            // Suggest a sensible default: next-higher type, count 1.
            const fromVal = parseInt(card.querySelector('input[data-field="from"]').value, 10) || 1;
            // Find used types to avoid trivial duplicates.
            const usedTypes = new Set(
                [...bundlesWrap.querySelectorAll('input[data-field="bundle-type"]')]
                    .map(i => parseInt(i.value, 10))
                    .filter(Number.isFinite)
            );
            let suggestedType = fromVal + 1;
            while (usedTypes.has(suggestedType)) suggestedType++;
            bundlesWrap.appendChild(buildBundleNode({ type: suggestedType, count: 1 }));
            persistFallbackRulesFromDOM();
            return;
        }

        const delRuleBtn = e.target.closest('button[data-action="delete-rule"]');
        if (delRuleBtn) {
            const card = delRuleBtn.closest('.fr-rule');
            if (!card) return;
            card.remove();
            persistFallbackRulesFromDOM();
            if (list.querySelectorAll('.fr-rule').length === 0) renderFallbackRules();
            return;
        }
    });

    if (els.btnAddFallbackRule) {
        els.btnAddFallbackRule.addEventListener('click', () => {
            const rules = collectFallbackRulesFromDOM();
            const used = new Set(rules.map(r => r.from));
            let nextFrom = 2;
            while (used.has(nextFrom)) nextFrom++;
            rules.push({ from: nextFrom, to: [] });
            saveFallbackRules(rules);
            renderFallbackRules();
            markFallbackRulesDirty();
        });
    }

    if (els.btnResetFallbackRules) {
        els.btnResetFallbackRules.addEventListener('click', () => {
            if (!confirm('سيتم استعادة قواعد البدائل الافتراضية محليًا. اضغط "حفظ على الخادم" لمشاركتها مع باقي المستخدمين. هل تريد المتابعة؟')) return;
            saveFallbackRules(cloneDefaultFallbackRules());
            renderFallbackRules();
            markFallbackRulesDirty();
            showToast('تم استعادة القواعد الافتراضية محليًا', 'info');
        });
    }
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

    els.fallbackRulesPanel = document.getElementById('fallbackRules');
    els.fallbackRulesBody = document.getElementById('fallbackRulesBody');
    els.fallbackRulesList = document.getElementById('fallbackRulesList');
    els.fallbackRulesStatus = document.getElementById('fallbackRulesStatus');
    els.btnAddFallbackRule = document.getElementById('btnAddFallbackRule');
    els.btnResetFallbackRules = document.getElementById('btnResetFallbackRules');
    els.btnSaveFallbackRules = document.getElementById('btnSaveFallbackRules');
    els.btnToggleFallbackRules = document.getElementById('btnToggleFallbackRules');

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

    enableTabInsertion(els.roomsInput);
    enableTabInsertion(els.groupsInput);

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

    initFallbackRulesUI();
    initAutoFillRoomsModal();
});
