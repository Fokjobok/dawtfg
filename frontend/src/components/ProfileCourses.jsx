import { useEffect, useState } from 'react'
import Button from './Button.jsx'
import { getEnrollmentPage } from '../services/enrollments.js'
import styles from './ProfileCourses.module.scss'

export default function ProfileCourses({ courses }) {
  if (courses.length === 0) return null

  return (
    <section
      className={styles.featured}
      aria-labelledby="profile-courses-title"
    >
      <h2 id="profile-courses-title">Cursos destacados</h2>

      <ul className={styles.list}>
        {courses.map((course) => (
          <li key={course.id}>
            <article className={styles.course}>
              {course.cover_url && (
                <img
                  src={course.cover_url}
                  alt=""
                  width={80}
                  height={56}
                  loading="lazy"
                  decoding="async"
                />
              )}

              <div className={styles.details}>
                <h3>{course.title}</h3>
                <p className={styles.meta}>
                  Curso #{course.id} · {course.specialty.name}
                </p>
                <p className={styles.meta}>{course.coach.name}</p>
              </div>
            </article>
          </li>
        ))}
      </ul>
    </section>
  )
}

export function EnrolledCoursePicker({
  selectedIds,
  onChange,
  disabled,
  errors = [],
}) {
  const [page, setPage] = useState(1)
  const [retry, setRetry] = useState(0)
  const [result, setResult] = useState(null)

  const queryKey = `${page}:${retry}`

  useEffect(() => {
    let active = true

    getEnrollmentPage(page)
      .then((response) => {
        if (!active) return

        if (page > response.meta.last_page) {
          setPage(response.meta.last_page)
          return
        }

        setResult({ key: queryKey, response })
      })
      .catch(() => {
        if (active) {
          setResult({ key: queryKey, error: true })
        }
      })

    return () => {
      active = false
    }
  }, [page, queryKey])

  const status =
    result?.key !== queryKey ? 'loading' : result.error ? 'error' : 'ready'

  const enrollments = status === 'ready' ? result.response.data : []
  const pagination = result?.response?.meta

  function toggleCourse(courseId) {
    if (disabled) return

    if (selectedIds.includes(courseId)) {
      onChange(selectedIds.filter((id) => id !== courseId))
    } else if (selectedIds.length < 3) {
      onChange([...selectedIds, courseId])
    }
  }

  return (
    <fieldset
      className={styles.picker}
      disabled={disabled}
      aria-invalid={errors.length > 0}
      aria-describedby={
        errors.length
          ? 'featured-courses-help featured-courses-error'
          : 'featured-courses-help'
      }
      tabIndex={errors.length ? -1 : undefined}
      aria-busy={status === 'loading'}
    >
      <legend>Mis cursos y destacados</legend>

      <p id="featured-courses-help">
        Elige hasta tres cursos inscritos. Se mostrarán en el orden en que los
        selecciones y se guardarán al pulsar «Guardar cambios».
      </p>

      {selectedIds.length > 0 && (
        <ol className={styles.selection} aria-label="Orden de destacados">
          {selectedIds.map((id, index) => (
            <li key={id}>
              <span>
                {index + 1}. Curso #{id}
              </span>
              <Button
                variant="secondary"
                aria-label={`Quitar curso ${id} de destacados`}
                onClick={() => toggleCourse(id)}
              >
                Quitar
              </Button>
            </li>
          ))}
        </ol>
      )}

      {errors.length > 0 && (
        <p id="featured-courses-error" className={styles.error}>
          {errors.join(' ')}
        </p>
      )}

      {status === 'loading' && <p role="status">Cargando tus cursos…</p>}

      {status === 'error' && (
        <div>
          <p role="alert">No se pudieron cargar tus cursos.</p>
          <Button onClick={() => setRetry((current) => current + 1)}>
            Reintentar
          </Button>
        </div>
      )}

      {status === 'ready' && enrollments.length === 0 && (
        <p>Todavía no tienes inscripciones.</p>
      )}

      <ul className={styles.list}>
        {enrollments.map((enrollment) => {
          const checked = selectedIds.includes(enrollment.course_id)
          const course = enrollment.course

          return (
            <li key={enrollment.id}>
              <label className={`${styles.course} ${styles.choice}`}>
                <input
                  type="checkbox"
                  checked={checked}
                  disabled={
                    disabled ||
                    (!checked && (!course || selectedIds.length >= 3))
                  }
                  onChange={() => toggleCourse(enrollment.course_id)}
                />

                {course?.cover_url && (
                  <img
                    src={course.cover_url}
                    alt=""
                    width={80}
                    height={56}
                    loading="lazy"
                    decoding="async"
                  />
                )}

                <span className={styles.details}>
                  <strong>
                    {course?.title ?? `Curso #${enrollment.course_id}`}
                  </strong>
                  <span className={styles.meta}>
                    #{enrollment.course_id}
                    {course
                      ? ` · ${course.specialty.name}`
                      : ' · Ya no está disponible en el catálogo'}
                  </span>
                </span>
              </label>
            </li>
          )
        })}
      </ul>

      {pagination && pagination.last_page > 1 && (
        <div className={styles.actions}>
          <Button
            variant="secondary"
            disabled={disabled || status !== 'ready' || page <= 1}
            onClick={() => setPage((current) => current - 1)}
          >
            Anterior
          </Button>

          <span>
            Página {page} de {pagination.last_page}
          </span>

          <Button
            variant="secondary"
            disabled={
              disabled || status !== 'ready' || page >= pagination.last_page
            }
            onClick={() => setPage((current) => current + 1)}
          >
            Siguiente
          </Button>
        </div>
      )}
    </fieldset>
  )
}
