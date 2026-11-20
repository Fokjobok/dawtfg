import { useEffect, useState } from 'react'
import { useParams } from 'react-router'
import Button from '../components/Button.jsx'
import FormField from '../components/FormField.jsx'
import { getCourse, updateCourse } from '../services/courses.js'

export default function EditCoursePage() {
  const { courseId } = useParams()

  return <CourseLevelEditor key={courseId} courseId={courseId} />
}

function CourseLevelEditor({ courseId }) {
  const [course, setCourse] = useState(null)
  const [level, setLevel] = useState('')
  const [loadError, setLoadError] = useState(false)
  const [saveStatus, setSaveStatus] = useState('idle')
  const [errors, setErrors] = useState({})
  const [message, setMessage] = useState('')

  const saving = saveStatus === 'saving'

  useEffect(() => {
    let active = true

    getCourse(courseId)
      .then((loaded) => {
        if (!active) return

        setCourse(loaded)
        setLevel(loaded.level ?? '')
      })
      .catch(() => {
        if (active) setLoadError(true)
      })

    return () => {
      active = false
    }
  }, [courseId])

  async function handleSubmit(event) {
    event.preventDefault()

    if (saving) return

    setSaveStatus('saving')
    setErrors({})
    setMessage('')

    try {
      const updated = await updateCourse(courseId, { level })

      setCourse(updated)
      setLevel(updated.level ?? '')
      setMessage('Nivel del curso actualizado.')
      setSaveStatus('success')
    } catch (error) {
      setErrors(error.errors ?? {})
      setMessage(
        error.status === 422
          ? 'Revisa los datos del curso.'
          : 'No se pudo guardar. Recarga la página e inténtalo de nuevo.',
      )
      setSaveStatus('error')
    }
  }

  if (loadError) {
    return (
      <p role="alert">
        No se pudo abrir el curso o no tienes permiso para editarlo.
      </p>
    )
  }

  if (!course) {
    return <p role="status">Cargando curso…</p>
  }

  return (
    <section aria-labelledby="edit-course-title">
      <h1 id="edit-course-title">Editar curso</h1>
      <p>{course.title}</p>

      <form onSubmit={handleSubmit} aria-busy={saving}>
        <fieldset disabled={saving}>
          <legend>Clasificación del curso</legend>

          <FormField
            as="select"
            id="edit-course-level"
            name="level"
            label="Nivel del curso"
            value={level}
            onChange={(event) => setLevel(event.target.value)}
            errors={errors.level}
            required
          >
            <option value="">Selecciona un nivel</option>
            <option value="beginner">Principiante</option>
            <option value="advanced">Avanzado</option>
            <option value="expert">Experto</option>
          </FormField>

          {message && (
            <p role={saveStatus === 'error' ? 'alert' : 'status'}>{message}</p>
          )}

          <Button type="submit" disabled={saving}>
            {saving ? 'Guardando…' : 'Guardar cambios'}
          </Button>
        </fieldset>
      </form>
    </section>
  )
}
