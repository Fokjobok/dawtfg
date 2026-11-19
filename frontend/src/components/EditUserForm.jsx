import { useEffect, useState } from 'react'

import Button from './Button.jsx'
import FormField from './FormField.jsx'

import { ROLE_LABELS } from '../roles.js'
import { updateUser } from '../services/users.js'

import { getSpecialties, createSpecialty, } from '../services/specialities.js'


export default function EditUserForm({ account, onSaved, onCancel }) {
  const [form, setForm] = useState({
    name: account.name,
    email: account.email,
    role: account.role,
    specialty_ids: account.specialties?.map((specialty) => specialty.id) ?? [],
  })

  const [errors, setErrors] = useState({})
  const [message, setMessage] = useState('')
  const [saving, setSaving] = useState(false)

  const [specialties, setSpecialties] = useState(account.specialties ?? [])
  const [specialtiesStatus, setSpecialtiesStatus] = useState('loading')

  const [showSpecialtyForm, setShowSpecialtyForm] = useState(false)
  const [specialtyName, setSpecialtyName] = useState('')
  const [creatingSpecialty, setCreatingSpecialty] = useState(false)

  const [specialtyError, setSpecialtyError] = useState('')


  useEffect(() => {
    let active = true

    async function loadSpecialties() {
      try {
        const items = []
        let page = 1
        let lastPage = 1

        do {
          const response = await getSpecialties(page)

          if (!active) return

          items.push(...response.data)
          lastPage = response.meta.last_page
          page += 1
        } while (page <= lastPage)

        setSpecialties(items)
        setSpecialtiesStatus('ready')
      } catch {
        if (active) {
          setSpecialtiesStatus('error')
        }
      }
    }
        loadSpecialties()

    return () => {
      active = false
    }
  }, [])


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
      const updated = await updateUser(account.id, {
        ...form,
        specialty_ids: form.role === 'Coach' ? form.specialty_ids : [],
      })
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

  //Convierte las opciones seleccionadas en lista de id
  function handleSpecialtiesChange(event) {
    const ids = Array.from(
      event.target.selectedOptions,
      (option) => Number(option.value),
    )

    setForm((current) => ({
      ...current,
      specialty_ids: ids,
    }))
  }

  //Crea una especialidad y la añade
  async function handleCreateSpecialty() {
    if (creatingSpecialty) return

    setCreatingSpecialty(true)
    setSpecialtyError('')

    try {
      const created = await createSpecialty(specialtyName)

      setSpecialties((current) => [...current, created])
      setSpecialtyName('')
      setShowSpecialtyForm(false)
    } catch (error) {
      setSpecialtyError(
        error.errors?.name?.join(' ') ||
          'No se pudo crear la especialidad.',
      )
    } finally {
      setCreatingSpecialty(false)
    }
  }

  return (
    <section aria-labelledby="edit-user-title">
      <h2 id="edit-user-title">Editar usuario</h2>

      <form onSubmit={handleSubmit} aria-busy={saving}>
        <fieldset disabled={saving || creatingSpecialty}>
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

                    {form.role === 'Coach' && (
            <fieldset>
              <legend>Especialidades del Coach</legend>

              <p>
                Grupos seleccionados:{' '}
                {specialties
                  .filter((specialty) =>
                    form.specialty_ids.includes(specialty.id),
                  )
                  .map((specialty) => specialty.name)
                  .join(', ') || 'Ninguna'}
              </p>

              {specialtiesStatus === 'loading' && (
                <p role="status">Cargando especialidades…</p>
              )}

              {specialtiesStatus === 'error' && (
                <p role="alert">
                  No se pudieron cargar las especialidades.
                </p>
              )}

              <FormField
                as="select"
                id="edit-specialties"
                label="Especialidades"
                multiple
                value={form.specialty_ids.map(String)}
                onChange={handleSpecialtiesChange}
                disabled={specialtiesStatus !== 'ready'}
                errors={errors.specialty_ids}
                hint="Mantén Ctrl pulsado para seleccionar o desmarcar varios grupos."
              >
                {specialties.map((specialty) => (
                  <option key={specialty.id} value={specialty.id}>
                    {specialty.name}
                  </option>
                ))}
              </FormField>

              <Button
                type="button"
                aria-label="Crear especialidad"
                aria-expanded={showSpecialtyForm}
                disabled={specialtiesStatus !== 'ready'}
                onClick={() => setShowSpecialtyForm((current) => !current)}
              >
                +
              </Button>

              {showSpecialtyForm && (
                <fieldset>
                  <legend>Nueva especialidad</legend>

                  <FormField
                    id="new-specialty-name"
                    label="Nombre de la especialidad"
                    value={specialtyName}
                    onChange={(event) =>
                      setSpecialtyName(event.target.value)
                    }
                    onKeyDown={(event) => {
                      if (event.key === 'Enter') {
                        event.preventDefault()
                        handleCreateSpecialty()
                      }
                    }}
                    maxLength={100}
                    errors={specialtyError ? [specialtyError] : undefined}
                  />

                  <Button type="button" onClick={handleCreateSpecialty}>
                    {creatingSpecialty ? 'Añadiendo…' : 'Añadir'}
                  </Button>

                  <Button
                    type="button"
                    variant="secondary"
                    onClick={() => setShowSpecialtyForm(false)}
                  >
                    Cancelar
                  </Button>
                </fieldset>
              )}
            </fieldset>
          )}

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
