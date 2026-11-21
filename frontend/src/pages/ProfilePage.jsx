import { useEffect, useState } from 'react'
import { useParams } from 'react-router'
import { getPublicProfile } from '../services/profiles.js'

import styles from './ProfilePage.module.scss'
import EditProfileForm from '../components/EditProfileForm.jsx'

export default function ProfilePage() {
  const { userId } = useParams()

  return <ProfileDetails key={userId} userId={userId} />
}

function ProfileDetails({ userId }) {
  const [profile, setProfile] = useState(null)
  const [error, setError] = useState('')

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
    <section aria-labelledby="profile-title">
      <h1 id="profile-title">{profile.name}</h1>

      {profile.avatar_url ? (
        <img
          className={styles.avatar}
          src={profile.avatar_url}
          alt={`Avatar de ${profile.name}`}
          width={128}
          height={128}
          decoding="async"
        />
      ) : (
        <p>Sin avatar.</p>
      )}

      <p>Nivel {profile.level}</p>

      {profile.can_edit && (
        <p>Tu perfil es {profile.is_private ? 'privado' : 'público'}.</p>
      )}

      {Object.hasOwn(profile, 'bio') && (
        <section aria-labelledby="profile-bio-title">
          <h2 id="profile-bio-title">Biografía</h2>
          <p className={styles.bio}>
            {profile.bio || 'Todavía no hay una biografía.'}
          </p>
        </section>
      )}

      {profile.can_edit && (
        <EditProfileForm profile={profile} onSaved={setProfile} />
      )}
    </section>
  )
}
