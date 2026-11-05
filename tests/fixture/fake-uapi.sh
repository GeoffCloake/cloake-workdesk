#!/usr/bin/env bash
# Stand-in for cPanel's uapi (tests only): uapi --user=U --output=json Module function
U=""; for a in "$@"; do case "$a" in --user=*) U="${a#--user=}";; esac; done
M="${@: -2:1}"; F="${@: -1}"
case "$U:$M:$F" in
  testacct1:DomainInfo:list_domains) echo '{"result":{"status":1,"data":{"main_domain":"test-c01.example.nz","addon_domains":["test-c01-addon.example.nz"],"parked_domains":[],"sub_domains":[]}}}';;
  testacct1:Email:list_pops) echo '{"result":{"status":1,"data":[{"email":"testacct1","login":"testacct1"},{"email":"info@test-c01.example.nz","login":"info@test-c01.example.nz"},{"email":"sales@test-c01.example.nz"},{"email":"shop@test-c01-addon.example.nz"},{"email":"webmailer@test-c01.example.nz"},{"email":"forms@mail.test-c01.example.nz"}]}}';;
  testacct1:Email:list_forwarders) echo '{"result":{"status":1,"data":[{"dest":"hello@test-c01.example.nz","forward":"someone@gmail.example"}]}}';;
  testacct2:DomainInfo:list_domains) echo '{"result":{"status":1,"data":{"main_domain":"test-c01-orphan.example.nz"}}}';;
  testacct2:Email:list_pops|testacct2:Email:list_forwarders) echo '{"result":{"status":1,"data":[]}}';;
  *) echo '{"result":{"status":0,"errors":["no such account"]}}';;
esac
