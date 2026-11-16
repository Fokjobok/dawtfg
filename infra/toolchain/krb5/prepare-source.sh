#!/bin/sh
set -eu

packaging_version='1.22.1-3'
upstream_version='1.22.3'
signer='C4493CB739F4A89F9852CBC20CBA08575F8372DF'
archive="krb5-${upstream_version}.tar.gz"
release_url='https://kerberos.org/dist/krb5/1.22'

printf '%s\n' 'deb-src http://deb.debian.org/debian sid main' > /etc/apt/sources.list.d/krb5-source.list
apt-get update
apt-get install -y --no-install-recommends build-essential ca-certificates curl dpkg-dev fakeroot gpg gpgv
apt-get build-dep -y --arch-only --no-install-recommends "krb5=$packaging_version"
apt-get source --download-only "krb5=$packaging_version"
dpkg-source --skip-patches -x "krb5_${packaging_version}.dsc" packaging

curl --fail --silent --show-error --location --retry 2 "$release_url/$archive" -o "$archive"
curl --fail --silent --show-error --location --retry 2 "$release_url/$archive.asc" -o "$archive.asc"
gpg --batch --dearmor --output /build/mit-release.gpg packaging/debian/upstream/signing-key.asc
gpgv --keyring /build/mit-release.gpg --status-fd 1 "$archive.asc" "$archive" > signature.txt
grep -F "[GNUPG:] VALIDSIG $signer " signature.txt
printf '%s  %s\n' 'fe90af8441ad9fa9b064b8e53077cefb782a96f8a68276b031dc54af1789ff0b' "$archive" | sha256sum -c -

mkdir source
tar -xzf "$archive" --strip-components=1 -C source
cp -a packaging/debian source/debian
printf '%s\n' "$upstream_version" > /build/upstream-version
