import { useState } from 'react'
import Button from './Button.jsx'
import FormField from './FormField.jsx'

import { updateOwnProfile } from '../services/profiles.js'

export default function EditProfileForm({ profile, onSaved }) {
  const [name, setName] = useState(profile.name)
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
      const updated = await updateOwnProfile({ name })

      setName(updated.name)
      onSaved(updated)
      setFeedback({
        error: false,
        message: 'Nombre actualizado.',
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
        <legend>Datos públicos</legend>

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
