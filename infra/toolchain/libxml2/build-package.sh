#!/bin/sh
set -eu

source_version='2.12.7+dfsg+really2.9.14-2.1+deb13u3'
patched_version="${source_version}+dawtfg1"
patch_file='CVE-2026-86140.patch'

printf '%s  %s\n' '5e51f8dcadfe3294a15d9e8644d61e8d92bd5b9e72c10cf13ef010eccfbfa4df' "$patch_file" | sha256sum -c -
sed -i 's/^Types: deb$/Types: deb deb-src/' /etc/apt/sources.list.d/debian.sources
apt-get update
apt-get install -y --no-install-recommends build-essential dpkg-dev fakeroot
apt-get build-dep -y --no-install-recommends "libxml2=$source_version"
apt-get source --download-only "libxml2=$source_version"
dpkg-source -x "libxml2_${source_version}.dsc" source
cd source

patch --dry-run --batch --fuzz=0 -p1 < "../$patch_file"
cp "../$patch_file" "debian/patches/$patch_file"
printf '\n%s\n' "$patch_file" >> debian/patches/series

{
    printf 'libxml2 (%s) trixie; urgency=high\n\n' "$patched_version"
    printf '  * Backport the upstream bounds checks for CVE-2026-86140.\n\n'
    printf ' -- DAW TFG <build@dawtfg.invalid>  Tue, 10 Nov 2026 00:00:00 +0000\n\n'
    cat debian/changelog
} > debian/changelog.patched
mv debian/changelog.patched debian/changelog

dpkg-buildpackage -B -us -uc -j4
package="../libxml2_${patched_version}_$(dpkg-architecture -qDEB_HOST_ARCH).deb"
test "$(dpkg-deb -f "$package" Version)" = "$patched_version"
install -Dm644 "$package" /out/libxml2.deb
sha256sum /out/libxml2.deb
