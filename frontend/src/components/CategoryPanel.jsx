import { useEffect, useState } from 'react'
import Button from './Button.jsx'
import FormField from './FormField.jsx'

import { useToasts } from './ToastProvider.jsx'
import {
  classifySpecialty,
  createCategory,
  getCategoryOptions,
} from '../services/categories.js'

import styles from './CategoryPanel.module.scss'

export default function CategoryPanel() {
  const { notify } = useToasts()

  const [options, setOptions] = useState(null)
  const [status, setStatus] = useState('loading')
  const [reload, setReload] = useState(0)

  const [name, setName] = useState('')
  const [specialtyId, setSpecialtyId] = useState('')
  const [categoryId, setCategoryId] = useState('')

  const [busy, setBusy] = useState(false)
  const [errors, setErrors] = useState({})

  useEffect(() => {
    let active = true

    getCategoryOptions()
      .then((result) => {
        if (!active) return

        setOptions(result)
        setStatus('ready')
      })
      .catch(() => {
        if (active) setStatus('error')
      })

    return () => {
      active = false
    }
  }, [reload])

  async function perform(action, message) {
    if (busy) return

    setBusy(true)
    setErrors({})

    try {
      await action()
      notify('success', message)
    } catch (error) {
      setErrors(error.errors ?? {})
      notify(
        'error',
        error.status === 422
          ? 'Revisa los datos del formulario.'
          : 'No se pudo completar la operación.',
      )
    } finally {
      setBusy(false)
    }
  }

  function handleCreate(event) {
    event.preventDefault()

    if (!name.trim()) return

    perform(async () => {
      const created = await createCategory(name.trim())

      setOptions((current) => ({
        ...current,
        categories: [...current.categories, created].sort((a, b) =>
          a.name.localeCompare(b.name),
        ),
      }))

      setName('')
      setCategoryId(String(created.id))
    }, 'Categoría creada correctamente.')
  }

  function handleSpecialtyChange(event) {
    const value = event.target.value
    const selected = options.specialties.find(
      (specialty) => String(specialty.id) === value,
    )

    setSpecialtyId(value)
    setCategoryId(String(selected?.category?.id ?? ''))
  }

  function handleSave(event) {
    event.preventDefault()

    if (!changed) return

    perform(async () => {
      const updated = await classifySpecialty(
        specialtyId,
        categoryId ? Number(categoryId) : null,
      )

      setOptions((current) => ({
        ...current,
        specialties: current.specialties.map((specialty) =>
          specialty.id === updated.id ? updated : specialty,
        ),
      }))
    }, 'Categoría asignada correctamente.')
  }

  if (status === 'loading') {
    return <p role="status">Cargando categorías…</p>
  }

  if (status === 'error') {
    return (
      <div>
        <p role="alert">No se pudieron cargar las categorías.</p>
        <Button
          onClick={() => {
            setStatus('loading')
            setReload((current) => current + 1)
          }}
        >
          Reintentar
        </Button>
      </div>
    )
  }

  const selected = options.specialties.find(
    (specialty) => String(specialty.id) === specialtyId,
  )

  const changed =
    Boolean(selected) && String(selected.category?.id ?? '') !== categoryId

  return (
    <section className={styles.panel} aria-labelledby="categories-title">
      <h2 id="categories-title">Categorías</h2>

      <div className={styles.forms}>
        <form onSubmit={handleCreate} aria-busy={busy}>
          <fieldset className={styles.fields} disabled={busy}>
            <legend>Crear categoría</legend>

            <FormField
              id="new-category-name"
              label="Nombre de la categoría"
              hint="Por ejemplo: Videojuegos o Asignaturas."
              value={name}
              onChange={(event) => setName(event.target.value)}
              maxLength={100}
              errors={errors.name}
              required
            />

            <Button type="submit" disabled={!name.trim()}>
              Crear categoría
            </Button>
          </fieldset>
        </form>

        <form onSubmit={handleSave} aria-busy={busy}>
          <fieldset className={styles.fields} disabled={busy}>
            <legend>Clasificar videojuegos y materias</legend>

            <FormField
              as="select"
              id="category-specialty"
              label="Videojuego o materia"
              value={specialtyId}
              onChange={handleSpecialtyChange}
              required
            >
              <option value="">Selecciona una especialidad</option>
              {options.specialties.map((specialty) => (
                <option key={specialty.id} value={specialty.id}>
                  {specialty.name} —{' '}
                  {specialty.category?.name ?? 'Sin categoría'}
                </option>
              ))}
            </FormField>

            <FormField
              as="select"
              id="specialty-category"
              label="Categoría"
              value={categoryId}
              onChange={(event) => setCategoryId(event.target.value)}
              errors={errors.category_id}
              disabled={!specialtyId}
            >
              <option value="">Sin categoría</option>
              {options.categories.map((category) => (
                <option key={category.id} value={category.id}>
                  {category.name}
                </option>
              ))}
            </FormField>

            <Button type="submit" disabled={!changed}>
              Guardar categoría
            </Button>
          </fieldset>
        </form>
      </div>
    </section>
  )
}
