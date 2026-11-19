import FormField from '../components/FormField.jsx'
import { useEffect, useState } from 'react'
import Button from '../components/Button.jsx'
import {
  getSpecialties,
  getSpecialtyCoaches,
} from '../services/specialities.js'
import { createCourse } from '../services/courses.js'

const initialForm = {
  title: '',
  description: '',
  specialty_id: '',
  coach_id: '',
  additional_coach_ids: [],
  pricingType: 'free',
  price: '',
  status: 'draft',
}

export default function CreateCoursePage() {
  const [form, setForm] = useState(initialForm)
  const [specialties, setSpecialties] = useState([])
  const [specialtiesStatus, setSpecialtiesStatus] = useState('loading')

  const [coaches, setCoaches] = useState([])
  const [coachesStatus, setCoachesStatus] = useState('idle')

  const [cover, setCover] = useState(null)
  const [saveStatus, setSaveStatus] = useState('idle')
  const [errors, setErrors] = useState({})
  const [message, setMessage] = useState('')

  const saving = saveStatus === 'saving'

  const collaboratorErrors = Object.entries(errors)
    .filter(
      ([field]) =>
        field === 'additional_coach_ids' ||
        field.startsWith('additional_coach_ids.'),
    )
    .flatMap(([, messages]) => messages)

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

  useEffect(() => {
    const specialtyId = form.specialty_id

    if (!specialtyId) return

    let active = true

    async function loadCoaches() {
      try {
        const items = []
        let page = 1
        let lastPage = 1

        do {
          const response = await getSpecialtyCoaches(specialtyId, page)

          if (!active) return

          items.push(...response.data)
          lastPage = response.meta.last_page
          page += 1
        } while (page <= lastPage)

        setCoaches(items)
        setCoachesStatus('ready')
      } catch {
        if (active) {
          setCoachesStatus('error')
        }
      }
    }

    loadCoaches()

    return () => {
      active = false
    }
  }, [form.specialty_id])

  function handleChange(event) {
    const { name, value } = event.target

    setForm((current) => ({
      ...current,
      [name]: value,
    }))
  }

  async function handleSubmit(event) {
    event.preventDefault()

    if (saving) return

    const formElement = event.currentTarget

    setErrors({})
    setMessage('')

    if (cover && cover.size > 2 * 1024 * 1024) {
      setErrors({
        cover: ['La imagen no puede superar los 2 MB.'],
      })
      setMessage('Revisa la imagen de portada.')
      setSaveStatus('error')
      return
    }

    setSaveStatus('saving')

    try {
      const created = await createCourse(
        {
          ...form,
          price: form.pricingType === 'free' ? '0.00' : form.price,
        },
        cover,
      )

      setForm(initialForm)
      setCover(null)
      setCoaches([])
      setCoachesStatus('idle')
      formElement.reset()

      setMessage(
        created.status === 'published'
          ? `Curso «${created.title}» publicado.`
          : `Curso «${created.title}» guardado como borrador.`,
      )
      setSaveStatus('success')
    } catch (error) {
      setErrors(error.errors ?? {})

      if (error.status === 422) {
        setMessage('Revisa los campos del formulario.')
      } else if (error.status === 401 || error.status === 419) {
        setMessage('La sesión ha caducado. Inicia sesión de nuevo.')
      } else if (error.status === 403) {
        setMessage('No tienes permiso para completar esta operación.')
      } else {
        setMessage('No se pudo confirmar el guardado. Comprueba la conexión.')
      }

      setSaveStatus('error')
    }
  }

  function handleSpecialtyChange(event) {
    const specialtyId = event.target.value

    setForm((current) => ({
      ...current,
      specialty_id: specialtyId,
      coach_id: '',
      additional_coach_ids: [],
    }))

    setCoaches([])
    setCoachesStatus(specialtyId ? 'loading' : 'idle')
  }

  function handleOwnerChange(event) {
    const coachId = event.target.value

    setForm((current) => ({
      ...current,
      coach_id: coachId,
      additional_coach_ids: current.additional_coach_ids.filter(
        (id) => String(id) !== coachId,
      ),
    }))
  }

  function handleCoachesChange(event) {
    const ids = Array.from(event.target.selectedOptions, (option) =>
      Number(option.value),
    )

    setForm((current) => ({
      ...current,
      additional_coach_ids: ids,
    }))
  }

  return (
    <section aria-labelledby="create-course-title">
      <h1 id="create-course-title">Crear curso</h1>
      <p>Completa los datos para preparar un curso nuevo.</p>

      <form onSubmit={handleSubmit} aria-busy={saving}>
        <fieldset disabled={saving}>
          <legend>Datos del curso</legend>
          <FormField
            id="title"
            name="title"
            label="Título"
            value={form.title}
            onChange={handleChange}
            errors={errors.title}
            maxLength={255}
            required
          />

          <FormField
            as="textarea"
            id="description"
            name="description"
            label="Descripción"
            value={form.description}
            onChange={handleChange}
            errors={errors.description}
            rows={5}
            maxLength={10000}
            required
          />

          {specialtiesStatus === 'loading' && (
            <p role="status">Cargando especialidades…</p>
          )}

          {specialtiesStatus === 'error' && (
            <p role="alert">No se pudieron cargar las especialidades.</p>
          )}

          {specialtiesStatus === 'ready' && specialties.length === 0 && (
            <p>
              No tienes especialidades asignadas. Un administrador debe
              asignarte alguna para crear cursos.
            </p>
          )}

          <FormField
            as="select"
            id="specialty"
            name="specialty_id"
            label="Videojuego o materia"
            value={form.specialty_id}
            onChange={handleSpecialtyChange}
            errors={errors.specialty_id}
            disabled={specialtiesStatus !== 'ready' || specialties.length === 0}
            required
          >
            <option value="">Selecciona una especialidad</option>

            {specialties.map((specialty) => (
              <option key={specialty.id} value={specialty.id}>
                {specialty.name}
              </option>
            ))}
          </FormField>

          {form.specialty_id && (
            <fieldset disabled={coachesStatus !== 'ready'}>
              <legend>Equipo docente</legend>

              {coachesStatus === 'loading' && (
                <p role="status">Cargando Coaches…</p>
              )}

              {coachesStatus === 'error' && (
                <p role="alert">No se pudieron cargar los Coaches.</p>
              )}

              {coachesStatus === 'ready' && coaches.length === 0 && (
                <p>No hay Coaches asignados a esta especialidad.</p>
              )}

              <FormField
                as="select"
                id="course-coach"
                name="coach_id"
                label="Coach responsable"
                value={form.coach_id}
                onChange={handleOwnerChange}
                errors={errors.coach_id}
                disabled={coaches.length === 0}
                required
              >
                <option value="">Selecciona un responsable</option>

                {coaches.map((coach) => (
                  <option key={coach.id} value={coach.id}>
                    {coach.name}
                  </option>
                ))}
              </FormField>

              <FormField
                as="select"
                id="additional-coaches"
                name="additional_coach_ids"
                label="Coaches colaboradores"
                multiple
                value={form.additional_coach_ids.map(String)}
                onChange={handleCoachesChange}
                errors={collaboratorErrors}
                disabled={!form.coach_id || coaches.length < 2}
                hint="Opcional. Mantén Ctrl pulsado para seleccionar o desmarcar varios."
              >
                {coaches
                  .filter((coach) => String(coach.id) !== form.coach_id)
                  .map((coach) => (
                    <option key={coach.id} value={coach.id}>
                      {coach.name}
                    </option>
                  ))}
              </FormField>
            </fieldset>
          )}

          <FormField
            as="select"
            id="course-status"
            name="status"
            label="Estado del curso"
            value={form.status}
            onChange={handleChange}
            errors={errors.status}
            hint="Los publicados aparecen en el catálogo público."
          >
            <option value="draft">Borrador</option>
            <option value="published">Publicado</option>
          </FormField>

          <FormField
            as="select"
            id="pricing-type"
            name="pricingType"
            label="Modalidad de precio"
            value={form.pricingType}
            onChange={handleChange}
          >
            <option value="free">Gratuito</option>
            <option value="paid">De pago</option>
          </FormField>

          {form.pricingType === 'paid' && (
            <FormField
              id="price"
              name="price"
              label="Precio (€)"
              type="number"
              value={form.price}
              onChange={handleChange}
              min="0.01"
              max="99999999.99"
              step="0.01"
              errors={errors.price}
              required
            />
          )}

          <FormField
            id="cover"
            name="cover"
            label="Imagen de portada"
            type="file"
            accept="image/jpeg,image/png,image/webp"
            onChange={(event) => setCover(event.target.files?.[0] ?? null)}
            errors={errors.cover}
            hint="Opcional. JPG, PNG o WebP; máximo 2 MB y 4096 × 4096 píxeles."
          />

          {message && (
            <p role={saveStatus === 'error' ? 'alert' : 'status'}>{message}</p>
          )}

          <Button
            type="submit"
            disabled={saving || coachesStatus !== 'ready' || !form.coach_id}
          >
            {saving
              ? 'Guardando…'
              : form.status === 'published'
                ? 'Publicar curso'
                : 'Guardar borrador'}
          </Button>
        </fieldset>
      </form>
    </section>
  )
}
