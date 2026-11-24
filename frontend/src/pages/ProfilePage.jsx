import { useEffect, useState } from 'react'
import { useParams } from 'react-router'
import { getPublicProfile } from '../services/profiles.js'

import { useAuth } from '../context/AuthContext.jsx'

import styles from './ProfilePage.module.scss'
import EditProfileForm from '../components/EditProfileForm.jsx'

export default function ProfilePage() {
  const { userId } = useParams()

  return <ProfileDetails key={userId} userId={userId} />
}

function ProfileDetails({ userId }) {
  const [profile, setProfile] = useState(null)
  const [error, setError] = useState('')
  const { setUser } = useAuth()

  function handleProfileSaved(updated) {
    setProfile(updated)

    setUser((current) =>
      current?.id === updated.id
        ? {
            ...current,
            name: updated.name,
            avatar_url: updated.avatar_url,
          }
        : current,
    )
  }

  useEffect(() => {
    let active = true

    getPublicProfile(userId)
      .then((loadedProfile) => {
        if (active) setProfile(loadedProfile)
      })
      .catch((error) => {
        if (!active) return

        setError(
          error.status === 404
            ? 'Este perfil no existe.'
            : 'No se pudo cargar el perfil. Comprueba la conexión.',
        )
      })

    return () => {
      active = false
    }
  }, [userId])

  if (error) {
    return <p role="alert">{error}</p>
  }

  if (!profile) {
    return <p role="status">Cargando perfil…</p>
  }

  return (
    <section className={styles.page} aria-labelledby="profile-title">
      <div
        className={`${styles.card} ${
          !profile.can_edit && !profile.featured_courses?.length
            ? styles.compact
            : ''
        }`}
      >
        <header
          className={`${styles.header} ${
            !profile.avatar_url ? styles.withoutAvatar : ''
          }`}
        >
          <div className={styles.identity}>
            <p className={styles.eyebrow}>Perfil de usuario</p>

            <h1 id="profile-title">{profile.name}</h1>

            <div className={styles.metadata}>
              <p className={styles.level}>Nivel {profile.level}</p>

              {profile.can_edit && (
                <p className={styles.ownerState}>
                  Tu perfil es {profile.is_private ? 'privado' : 'público'}.
                </p>
              )}
            </div>
          </div>

          {profile.avatar_url && (
            <div className={styles.avatarBlock}>
              <img
                className={styles.avatar}
                src={profile.avatar_url}
                alt={`Avatar de ${profile.name}`}
                width={128}
                height={128}
                decoding="async"
              />
            </div>
          )}
        </header>

        {(Object.hasOwn(profile, 'bio') || profile.can_edit) && (
          <div
            className={`${styles.content} ${
              profile.can_edit ? styles.withSettings : ''
            }`}
          >
            {Object.hasOwn(profile, 'bio') && (
              <section
                className={styles.biography}
                aria-labelledby="profile-bio-title"
              >
                <h2 id="profile-bio-title">Biografía</h2>

                <p className={styles.bio}>
                  {profile.bio || 'Todavía no hay una biografía.'}
                </p>
              </section>
            )}

            {profile.can_edit && (
              <section
                className={styles.visibility}
                aria-labelledby="profile-privacy-title"
              >
                <h2 id="profile-privacy-title">Tu privacidad</h2>

                <dl className={styles.visibilityList}>
                  <div>
                    <dt>Perfil</dt>
                    <dd>{profile.is_private ? 'Privado' : 'Público'}</dd>
                  </div>

                  <div>
                    <dt>Biografía</dt>
                    <dd>
                      {profile.is_private || profile.bio_is_private
                        ? 'Solo tú'
                        : 'Visible para todos'}
                    </dd>
                  </div>
                </dl>

                <p className={styles.visibilityNote}>
                  Tu nombre, avatar y nivel siempre son públicos.
                </p>

                <a className={styles.editLink} href="#profile-edit-title">
                  Editar mis datos
                </a>
              </section>
            )}
          </div>
        )}
      </div>

      {profile.can_edit && (
        <EditProfileForm profile={profile} onSaved={handleProfileSaved} />
      )}
    </section>
  )
}
