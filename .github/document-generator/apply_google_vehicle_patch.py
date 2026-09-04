from pathlib import Path
ROOT=Path(__file__).resolve().parents[2]
p=ROOT/'.github/workflows/document_generator_regression.yml'
s=p.read_text(encoding='utf-8')

def one(old,new):
    global s
    n=s.count(old)
    if n!=1: raise SystemExit(f'expected 1 occurrence, got {n}: {old!r}')
    s=s.replace(old,new,1)

# Both PR and canonical-push path filters.
s=s.replace("      - 'app/Service/DocumentGenerator*.php'", "      - 'app/Service/DocumentGenerator*.php'\n      - 'app/Service/GoogleSheetsRequestContactService.php'")
s=s.replace("      - 'scripts/document_generator_template_v2.php'", "      - 'scripts/document_generator_template_v2.php'\n      - 'scripts/document_generator_template_v3.php'")
s=s.replace("      - 'tests/document_generator_template_v2_production_contract_test.php'", "      - 'tests/document_generator_template_v2_production_contract_test.php'\n      - 'tests/document_generator_template_v3_production_contract_test.php'\n      - 'tests/document_generator_google_vehicle_contract_test.php'")
s=s.replace("      - '.github/workflows/document_generator_template_v2_production.yml'", "      - '.github/workflows/document_generator_template_v2_production.yml'\n      - '.github/workflows/document_generator_template_v3_production.yml'\n      - '.github/workflows/document_generator_template_v3_agent_request.yml'")
# Each of the four replacements above intentionally occurs twice (PR + push).
for needle in ["app/Service/GoogleSheetsRequestContactService.php","scripts/document_generator_template_v3.php","tests/document_generator_google_vehicle_contract_test.php",".github/workflows/document_generator_template_v3_production.yml"]:
    if s.count(needle)<2: raise SystemExit('path filter wiring missing '+needle)

old='foreach (["contract_vehicle_lease_crew_v1.docx", "contract_vehicle_lease_crew_v2.docx", "waybill_driver_assignment_v1.docx"] as $name)'
new='foreach (["contract_vehicle_lease_crew_v1.docx", "contract_vehicle_lease_crew_v2.docx", "contract_vehicle_lease_crew_v3.docx", "waybill_driver_assignment_v1.docx"] as $name)'
one(old,new)
one('          test "$(sha256sum artifacts/document-generator/input_contract_vehicle_lease_crew_v2.docx | awk \'{print $1}\')" = "7b79a9ac558af1633b1ed2d6414bef1efe0222c67a543d1b4a8e802e6267a1fa"', '          test "$(sha256sum artifacts/document-generator/input_contract_vehicle_lease_crew_v2.docx | awk \'{print $1}\')" = "7b79a9ac558af1633b1ed2d6414bef1efe0222c67a543d1b4a8e802e6267a1fa"\n          test "$(sha256sum artifacts/document-generator/input_contract_vehicle_lease_crew_v3.docx | awk \'{print $1}\')" = "87584e74a9cfd6bc150fc5ea2c4f7b7045e8ff4b2fba7a5fb80b03ccfc97dff2"')
one('            tests/document_generator_template_v2_production_contract_test.php \\', '            tests/document_generator_template_v2_production_contract_test.php \\\n            tests/document_generator_template_v3_production_contract_test.php \\\n            tests/document_generator_google_vehicle_contract_test.php \\')
one('            scripts/document_generator_template_v2.php', '            scripts/document_generator_template_v2.php \\\n            scripts/document_generator_template_v3.php \\\n            app/Service/GoogleSheetsRequestContactService.php')
one("          php tests/document_generator_template_v2_production_contract_test.php | tee /tmp/document-generator-template-v2-contract.log", "          php tests/document_generator_template_v2_production_contract_test.php | tee /tmp/document-generator-template-v2-contract.log\n          php tests/document_generator_template_v3_production_contract_test.php | tee /tmp/document-generator-template-v3-contract.log\n          php tests/document_generator_google_vehicle_contract_test.php | tee /tmp/document-generator-google-vehicle.log")
one("          grep -Fqx 'DOCUMENT_GENERATOR_TEMPLATE_V2_PRODUCTION_CONTRACT=PASS' /tmp/document-generator-template-v2-contract.log", "          grep -Fqx 'DOCUMENT_GENERATOR_TEMPLATE_V2_PRODUCTION_CONTRACT=PASS' /tmp/document-generator-template-v2-contract.log\n          grep -Fqx 'DOCUMENT_GENERATOR_TEMPLATE_V3_PRODUCTION_CONTRACT=PASS' /tmp/document-generator-template-v3-contract.log\n          grep -Fqx 'DOCUMENT_GENERATOR_GOOGLE_VEHICLE_CONTRACT=PASS' /tmp/document-generator-google-vehicle.log")
p.write_text(s,encoding='utf-8')
print('DOCUMENT_GENERATOR_CI_WIRING_PATCH=PASS')
