import { useState } from 'react'
import Button from './Button.jsx'
import FormField from './FormField.jsx'
import { ROLE_LABELS } from '../roles.js'
import { updateUser } from '../services/users.js'

export default function EditUserForm({ account, onSaved, onCancel }) {
  const [form, setForm] = useState({
    name: account.name,
    email: account.email,
    role: account.role,
  })

  const [errors, setErrors] = useState({})
  const [message, setMessage] = useState('')
  const [saving, setSaving] = useState(false)

  function handleChange(event) {
    const { name, value } = event.target

    setForm((current) => ({
      ...current,
      [name]: value,
    }))
  }

  async function handleSubmit(event) {
    event.preventDefault()
    setSaving(true)
    setErrors({})
    setMessage('')

    try {
      const updated = await updateUser(account.id, form)
      onSaved(updated)
    } catch (error) {
      setErrors(error.errors ?? {})
      setMessage(
        error.status === 422
          ? 'Revisa los campos del formulario.'
          : 'No se pudo guardar el usuario.',
      )
    } finally {
      setSaving(false)
    }
  }

  return (
    <section aria-labelledby="edit-user-title">
      <h2 id="edit-user-title">Editar usuario</h2>

      <form onSubmit={handleSubmit} aria-busy={saving}>
        <fieldset disabled={saving}>
          <legend>Datos del usuario</legend>

          <FormField
            id="edit-name"
            name="name"
            label="Nombre"
            value={form.name}
            onChange={handleChange}
            errors={errors.name}
            maxLength={100}
            required
          />

          <FormField
            id="edit-email"
            name="email"
            label="Correo electrónico"
            type="email"
            value={form.email}
            onChange={handleChange}
            errors={errors.email}
            maxLength={254}
            required
          />

          <FormField
            as="select"
            id="edit-role"
            name="role"
            label="Rol"
            value={form.role}
            onChange={handleChange}
            errors={errors.role}
          >
            <option value="Coach">{ROLE_LABELS.Coach}</option>
            <option value="Student">{ROLE_LABELS.Student}</option>
          </FormField>

          {message && <p role="alert">{message}</p>}

          <Button type="submit">
            {saving ? 'Guardando…' : 'Guardar cambios'}
          </Button>

          <Button variant="secondary" onClick={onCancel}>
            Cancelar
          </Button>
        </fieldset>
      </form>
    </section>
  )
}
