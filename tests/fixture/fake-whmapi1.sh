#!/usr/bin/env bash
# Stand-in for whmapi1 listaccts (tests only)
echo '{"data":{"acct":[{"user":"testacct1","domain":"test-c01.example.nz","plan":"cloakecr_Standard","diskused":"512M","disklimit":"2000M","suspended":0},{"user":"testacct2","domain":"test-c01-orphan.example.nz","plan":"cloakecr_Basic","diskused":"10M","disklimit":"unlimited","suspended":1}]}}'
