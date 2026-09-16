"""Verify actual cutover snapshots, including resumed already-journalled schema138."""
import copy
import hashlib
import json
from pathlib import Path
import re
import sys


def snapshot(path):
    match = re.search(r'^WASTE_138_SNAPSHOT=(.+)$', Path(path).read_text(encoding='utf-8'), re.M)
    if not match:
        raise ValueError(f'{path}: snapshot missing')
    return json.loads(match.group(1))


def verify(before, after, checksum):
    if before['central'] != after['central']:
        raise ValueError('central/active tenant invariant changed')
    b = {row['company_id']: row for row in before['tenants']}
    a = {row['company_id']: row for row in after['tenants']}
    if b.keys() != a.keys():
        raise ValueError('active tenant set changed')
    for cid, row in a.items():
        old = b[cid]
        if row['migration_137'] != 1 or row['migration_138'] != 1:
            raise ValueError('Migration journal incomplete')
        if row['migration_138_checksum'] != checksum:
            raise ValueError('Migration checksum mismatch')
        if not all(row[k] == 1 for k in ['general_table', 'model_column', 'customer_nullable']):
            raise ValueError('Migration schema incomplete')
        if row['data'] != old['data']:
            raise ValueError(f'company {cid}: operational data or posted facts changed')
        if row['model_pending'] != 0 or str(row['model_default']) != '2':
            raise ValueError('financial transition incomplete')
        if row['protected_obligations'] != old['protected_obligations']:
            raise ValueError('Historical contractual obligations changed')
        protected = {int(o['source_parent_id']) for o in old['protected_obligations']}
        old_models = {int(k): int(v) for k, v in dict(old['model_versions']).items()}
        models = {int(k): int(v) for k, v in dict(row['model_versions']).items()}
        if models.keys() != old_models.keys():
            raise ValueError('Trip identities changed')
        expected = copy.deepcopy(old['payments'])
        for trip_id, version in old_models.items():
            if version not in (1, 2, 3):
                raise ValueError('Unknown financial model')
            target = (3 if trip_id in protected else 2) if version == 1 else version
            if models[trip_id] != target:
                raise ValueError('Incorrect preserved-settlement classification')
            if version != 1:
                continue
            def rows(side):
                values = [p for p in old['payments'][side] if int(p['waste_trip_id']) == trip_id]
                result = copy.deepcopy(values)
                for p in result:
                    if not p['condition_type']:
                        p.update(condition_type='after_end', days_count=3, days_kind='calendar')
                return result
            for side, source in [('general', 'customer'), ('customer', 'carrier'), ('carrier', None)]:
                expected[side] = [p for p in expected[side] if int(p['waste_trip_id']) != trip_id]
                if source:
                    expected[side].extend(rows(source))
        for side in expected:
            key = lambda p: (int(p['waste_trip_id']), int(p['sort_order']))
            if sorted(row['payments'][side], key=key) != sorted(expected[side], key=key):
                raise ValueError(f'company {cid}: {side} amount transfer mismatch')


if __name__ == '__main__':
    verify(snapshot(sys.argv[1]), snapshot(sys.argv[2]), hashlib.sha256(Path(sys.argv[3]).read_bytes()).hexdigest())
    print('WASTE_138_INVARIANTS_PASS=YES')
    print('WASTE_138_AMOUNT_TRANSFER_VERIFIED=YES')
    print('WASTE_138_HISTORICAL_SETTLEMENTS_PRESERVED=YES')
