#!/usr/bin/env python3
"""Keep Clauth's nginx vhost (live file + CloudPanel's stored template) answering for the app sign-in domains.

    vhost.py <vhost file> <cloudpanel db> <main host> [extra hosts...]

Sets both `server_name` lines to the main host + extra hosts and makes sure the exact /.well-known location proxies
the custom-domain ownership proof to Laravel. Prints "changed" when the live file changed. Run as root by domains.sh.
"""
import re, sqlite3, sys

vhost, db, main, extra = sys.argv[1], sys.argv[2], sys.argv[3], sys.argv[4:]
names = ' '.join([main] + sorted(set(h for h in extra if re.fullmatch(r'[a-z0-9.-]+', h) and h != main)))
OLD_LOC = r'location ~ ^/\.well-known/(openid-configuration|jwks\.json)$ {'
NEW_LOC = r'location ~ ^/\.well-known/(openid-configuration|jwks\.json|clauth-domain/[a-z0-9]{32})$ {'


def fix(text: str) -> str:
    text = re.sub(r'^(\s*)server_name [^;]*;', lambda m: f'{m.group(1)}server_name {names};', text, flags=re.M)
    return text.replace(OLD_LOC, NEW_LOC)


s = open(vhost).read()
new = fix(s)
if new != s:
    open(vhost + '.bak-domains', 'w').write(s)
    open(vhost, 'w').write(new)
    print('changed')

con = sqlite3.connect(db)
row = con.execute('select vhost_template from site where domain_name=?', (main,)).fetchone()
if row and row[0] and fix(row[0]) != row[0]:
    con.execute('update site set vhost_template=? where domain_name=?', (fix(row[0]), main))
    con.commit()
