from pathlib import Path

view_path = Path('app/View/pages/company_feo_requests.php')
test_path = Path('tests/feo_requests_contract_test.php')
view = view_path.read_text(encoding='utf-8')

def replace_once(text: str, old: str, new: str, label: str) -> str:
    count = text.count(old)
    if count != 1:
        raise SystemExit(f'{label}: expected 1 occurrence, found {count}')
    return text.replace(old, new, 1)

# 3) Return FEO to the same bounded wide-workspace/card treatment as Linear Trips / Route Executors.
view = replace_once(
    view,
    '/* FEO is a dense operational workspace: use the available canvas, not decorative margins. */\n.content.content--wide-workspace{padding-left:0!important;padding-right:0!important;padding-bottom:0!important}\n.content--wide-workspace .workspace-shell-1690{width:100%!important;max-width:none!important;margin:0!important;padding:0!important}\n.feo-page-head{padding:0 8px;margin-bottom:8px}\n.feo-table-card{border-left:0;border-right:0;border-radius:0;margin:0;min-width:0}',
    '.feo-table-card{min-width:0}',
    'standard workspace/card css'
)
view = replace_once(view, '.feo-freshness{margin:0 8px 8px}', '.feo-freshness{margin-bottom:8px}', 'freshness standard width')

# 2) modal-lg is 820px in ERP UI; 820 * 1.30 = 1066px.
view = replace_once(
    view,
    '.feo-history-modal .modal-body{padding:0!important;overflow:hidden}',
    '.feo-history-modal{width:1066px;max-width:calc(100vw - 32px)}\n.feo-history-modal .modal-body{padding:0!important;overflow:hidden}',
    'history modal width'
)
view = replace_once(
    view,
    '.feo-history-table th,.feo-history-table td{white-space:normal;overflow-wrap:anywhere;vertical-align:middle}',
    '.feo-history-table th,.feo-history-table td{white-space:nowrap;overflow-wrap:normal;overflow:hidden;text-overflow:ellipsis;vertical-align:middle}',
    'history row nowrap'
)
view = replace_once(
    view,
    '.feo-history-actions{display:flex;gap:4px;flex-wrap:wrap;justify-content:flex-end}',
    '.feo-history-actions{display:flex;gap:4px;flex-wrap:nowrap;justify-content:flex-end}',
    'history actions nowrap'
)

# 1) Do not blank valid rows while a newer auto-filter request is in flight.
view = replace_once(
    view,
    "      if(reset){state.generation++;state.offset=0;state.hasMore=true;body.innerHTML='';if(state.controller)state.controller.abort();}",
    "      if(reset){state.generation++;state.offset=0;state.hasMore=true;if(state.controller)state.controller.abort();}",
    'do not clear before response'
)
view = replace_once(
    view,
    "        setDiagnostics(data,reset);(data.rows||[]).forEach(addRow);state.offset+=Array.isArray(data.rows)?data.rows.length:0;state.hasMore=!!data.has_more;",
    "        var rows=Array.isArray(data.rows)?data.rows:[];if(reset)body.innerHTML='';rows.forEach(addRow);setDiagnostics(data,reset);state.offset+=rows.length;state.hasMore=!!data.has_more;",
    'atomic result rendering'
)
view_path.write_text(view, encoding='utf-8')

test = test_path.read_text(encoding='utf-8')
test = replace_once(
    test,
    "$ok('FEO workspace is edge-to-edge', str_contains($view, '.content.content--wide-workspace{padding-left:0!important;padding-right:0!important') && str_contains($view, 'width:100%!important;max-width:none!important'));",
    "$ok('FEO workspace uses standard bounded wide-workspace card', !str_contains($view, '.content.content--wide-workspace{padding-left:0!important') && !str_contains($view, 'width:100%!important;max-width:none!important') && str_contains($view, '.feo-table-card{min-width:0}'));",
    'workspace regression assertion'
)
anchor = "$ok('table scroll gets stable viewport height', str_contains($view, 'min-height:360px') && str_contains($view, \"scroll.style.height=height+'px'\"));\n"
addition = """$ok('partial ID results are replaced only after successful response', !str_contains($view, \"state.hasMore=true;body.innerHTML=''\") && str_contains($view, \"if(reset)body.innerHTML='';rows.forEach(addRow);setDiagnostics(data,reset)\"));
$ok('multiple missing IDs stay visible as one diagnostic list', str_contains($view, \"missing.join(', ')\"));
$ok('history modal is thirty percent wider than modal-lg', str_contains($view, '.feo-history-modal{width:1066px;max-width:calc(100vw - 32px)}'));
$ok('history rows and actions do not wrap', str_contains($view, 'white-space:nowrap;overflow-wrap:normal') && str_contains($view, '.feo-history-actions{display:flex;gap:4px;flex-wrap:nowrap'));
"""
if test.count(anchor) != 1:
    raise SystemExit('test anchor: expected 1 occurrence')
test = test.replace(anchor, anchor + addition, 1)
test_path.write_text(test, encoding='utf-8')
