#!/bin/sh
set -eu

read -r upstream_version < /build/upstream-version
package_version="${upstream_version}-0+dawtfg1"
cd /build/source

# Keep Debian's packaging patches unless the upstream release already contains them.
cp -a . ../patch-check
: > debian/patches/series.local
while read -r patch_file; do
    case "$patch_file" in
        ''|'#'*) continue ;;
        0014-Improve-future-OpenSSL-compatibility.patch)
            # The 1.22.3 branch includes ceee5d558a2be93a9b2df717be1ea42c5d83ee51.
            test "$upstream_version" = '1.22.3'
            printf 'Included in upstream 1.22.3: %s\n' "$patch_file"
            continue
            ;;
    esac
    patch_path="/build/source/debian/patches/$patch_file"
    case "$patch_file" in
        0012-Skip-keyring-tests-if-keyring-blocked-by-seccomp.patch)
            # Docker may deny add_key with EPERM instead of ENOSYS.
            sed -i \
                -e "s/'Function not implemented' in out/out.strip() in ('add_key: Function not implemented', 'add_key: Operation not permitted')/" \
                -e "s/'Unexpected output from keyctl: '+e.output/'Unexpected output from keyctl: '+out/" \
                "$patch_path"
            ;;
        0015-Use-X509_check_host-to-verify-KKDCP-server-cert.patch)
            cp /build/0015-kkdcp-hostname-verification.patch "$patch_path"
            ;;
    esac
    if patch -d ../patch-check -p1 --batch --fuzz=0 --forward --dry-run < "$patch_path"; then
        patch -d ../patch-check -p1 --batch --fuzz=0 --forward < "$patch_path"
        printf '%s\n' "$patch_file" >> debian/patches/series.local
    elif patch -d ../patch-check -p1 --batch --fuzz=0 --reverse --dry-run < "$patch_path"; then
        printf 'Already present upstream: %s\n' "$patch_file"
    else
        printf 'Cannot verify Debian patch: %s\n' "$patch_file" >&2
        exit 1
    fi
done < debian/patches/series
mv debian/patches/series.local debian/patches/series

{
    printf 'krb5 (%s) trixie; urgency=high\n\n' "$package_version"
    printf '  * Rebuild the signed MIT Kerberos security release for Debian Trixie.\n\n'
    printf ' -- DAW TFG <build@dawtfg.invalid>  Wed, 11 Nov 2026 00:00:00 +0000\n\n'
    cat debian/changelog
} > debian/changelog.local
mv debian/changelog.local debian/changelog

# MIT 1.22.3 adds this export. Keep Debian's strict check for all other symbols.
sed -i "/^ k5_rc_store@krb5_3_MIT /a\\ k5_ser_unpack_len@krb5_3_MIT $package_version" debian/libkrb5-3.symbols
dpkg-buildpackage -B -us -uc -j4
architecture=$(dpkg-architecture -qDEB_HOST_ARCH)
for library in libgssapi-krb5-2 libk5crypto3 libkrb5-3 libkrb5support0; do
    package="../${library}_${package_version}_${architecture}.deb"
    test "$(dpkg-deb -f "$package" Version)" = "$package_version"
    install -Dm644 "$package" "/out/$library.deb"
done
sha256sum /out/*.deb
