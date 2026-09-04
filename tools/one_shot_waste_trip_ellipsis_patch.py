from pathlib import Path

page_path = Path('app/View/pages/company_waste_trips.php')
page = page_path.read_text(encoding='utf-8')
old = ".waste-registry th:nth-child(7),.waste-registry td:nth-child(7){white-space:normal!important;overflow-wrap:anywhere!important;word-break:break-word;min-width:0!important;max-width:0}"
new = ".waste-registry th:nth-child(7),.waste-registry td:nth-child(7){white-space:normal!important;overflow-wrap:anywhere!important;word-break:break-word;min-width:0!important;max-width:0;text-overflow:clip!important}"
if page.count(old) != 1:
    raise SystemExit(f'expected exactly one registry request-cell CSS anchor, got {page.count(old)}')
page_path.write_text(page.replace(old, new, 1), encoding='utf-8')

test_path = Path('tests/waste_trip_owner_layout_contract_test.php')
test = test_path.read_text(encoding='utf-8')
old_test = "foreach(['waste-request-list','flex-wrap:wrap','overflow-wrap:anywhere','word-break:break-word','max-width:0']as$n)if(!str_contains($page,$n))$fail('registry wrap contract missing '.$n);"
new_test = "foreach(['waste-request-list','flex-wrap:wrap','overflow-wrap:anywhere','word-break:break-word','max-width:0','text-overflow:clip!important']as$n)if(!str_contains($page,$n))$fail('registry wrap contract missing '.$n);"
if test.count(old_test) != 1:
    raise SystemExit(f'expected exactly one owner-layout contract anchor, got {test.count(old_test)}')
test_path.write_text(test.replace(old_test, new_test, 1), encoding='utf-8')

print('WASTE_TRIP_ELLIPSIS_PATCH_BUILT=PASS')
