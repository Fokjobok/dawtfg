import { useState } from 'react'
import Button from './Button.jsx'
import FormField from './FormField.jsx'

import { updateOwnProfile } from '../services/profiles.js'

export default function EditProfileForm({ profile, onSaved }) {
  const [name, setName] = useState(profile.name)

  const [bio, setBio] = useState(profile.bio ?? '')
  const [isPrivate, setIsPrivate] = useState(profile.is_private)
  const [bioIsPrivate, setBioIsPrivate] = useState(
    profile.bio_is_private ?? false,
  )

  const [saving, setSaving] = useState(false)
  const [errors, setErrors] = useState({})
  const [feedback, setFeedback] = useState(null)

  async function handleSubmit(event) {
    event.preventDefault()

    if (saving) return

    setSaving(true)
    setErrors({})
    setFeedback(null)

    try {
      const updated = await updateOwnProfile({
        name,
        bio: bio || null,
        is_private: isPrivate,
        bio_is_private: bioIsPrivate,
      })

      setName(updated.name)
      setBio(updated.bio ?? '')
      setIsPrivate(updated.is_private)
      setBioIsPrivate(updated.bio_is_private ?? false)

      onSaved(updated)
      setFeedback({
        error: false,
        message: 'Perfil actualizado correctamente.',
      })
    } catch (error) {
      setErrors(error.errors ?? {})
      setFeedback({
        error: true,
        message: [401, 419].includes(error.status)
          ? 'La sesión ha caducado. Vuelve a iniciar sesión.'
          : error.status === 422
            ? 'Revisa los datos del formulario.'
            : 'No se pudo guardar el perfil. Inténtalo de nuevo.',
      })
    } finally {
      setSaving(false)
    }
  }

  return (
    <form onSubmit={handleSubmit} aria-busy={saving}>
      <h2>Editar mi perfil</h2>

      <fieldset disabled={saving}>
        <legend>Información del perfil</legend>

        <FormField
          id="profile-name"
          name="name"
          label="Nombre"
          autoComplete="name"
          required
          maxLength={100}
          value={name}
          onChange={(event) => setName(event.target.value)}
          errors={errors.name}
        />

        <FormField
          as="textarea"
          id="profile-bio"
          name="bio"
          label="Biografía"
          rows={5}
          maxLength={2000}
          value={bio}
          onChange={(event) => setBio(event.target.value)}
          errors={errors.bio}
        />

        <FormField
          as="select"
          id="profile-visibility"
          name="is_private"
          label="Visibilidad del perfil"
          hint="El nombre, el avatar y el nivel siempre son públicos."
          value={isPrivate ? 'private' : 'public'}
          onChange={(event) => setIsPrivate(event.target.value === 'private')}
          errors={errors.is_private}
        >
          <option value="public">Público</option>
          <option value="private">Privado</option>
        </FormField>

        <FormField
          as="select"
          id="profile-bio-visibility"
          name="bio_is_private"
          label="Visibilidad de la biografía"
          hint={
            isPrivate
              ? 'El perfil privado oculta esta sección. Se conserva tu preferencia.'
              : 'Elige si otras personas pueden leer tu biografía.'
          }
          value={bioIsPrivate ? 'private' : 'public'}
          onChange={(event) =>
            setBioIsPrivate(event.target.value === 'private')
          }
          disabled={isPrivate}
          errors={errors.bio_is_private}
        >
          <option value="public">Pública</option>
          <option value="private">Solo yo</option>
        </FormField>

        <Button type="submit">
          {saving ? 'Guardando…' : 'Guardar cambios'}
        </Button>
      </fieldset>

      {feedback && (
        <p role={feedback.error ? 'alert' : 'status'}>{feedback.message}</p>
      )}
    </form>
  )
}
