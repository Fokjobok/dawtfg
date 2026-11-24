import { useEffect, useState } from 'react'
import { Link } from 'react-router'

import Button from '../components/Button.jsx'
import CourseCard from '../components/CourseCard.jsx'
import FormField from '../components/FormField.jsx'

import { useToasts } from '../components/ToastProvider.jsx'
import { useAuth } from '../context/AuthContext.jsx'
import { getCourses } from '../services/courses.js'

import {
  enrollInCourse,
  getEnrollments,
  withdrawEnrollment,
} from '../services/enrollments.js'

import styles from './CoursesPage.module.scss'

export default function CoursesPage() {
  const { user, status: authStatus } = useAuth()
  const { notify } = useToasts()
  const userId = user?.id
  const isStudent = user?.role === 'Student'

  const [courses, setCourses] = useState([])
  const [page, setPage] = useState(1)
  const [pagination, setPagination] = useState(null)
  const [status, setStatus] = useState('loading')
  const [level, setLevel] = useState('')
  const [enrollments, setEnrollments] = useState({})
  const [enrollmentStatus, setEnrollmentStatus] = useState('idle')
  const [pendingCourseId, setPendingCourseId] = useState(null)

  useEffect(() => {
    let active = true

    async function loadCourses() {
      try {
        const response = await getCourses(page, { level })
        if (!active) return

        let ownEnrollments = {}
        let ownStatus = isStudent ? 'ready' : 'idle'

        if (isStudent && userId && response.data.length > 0) {
          try {
            const result = await getEnrollments(
              response.data.map((course) => course.id),
            )

            ownEnrollments = Object.fromEntries(
              result.data.map((item) => [item.course_id, item.id]),
            )
          } catch {
            ownStatus = 'error'
          }
        }

        if (!active) return

        setCourses(response.data)
        setPagination(response.meta)

        setEnrollments(ownEnrollments)
        setEnrollmentStatus(ownStatus)

        setStatus('ready')
      } catch {
        if (active) setStatus('error')
      }
    }

    loadCourses()

    return () => {
      active = false
    }
  }, [page, level, isStudent, userId])

  function handleLevelChange(event) {
    if (pendingCourseId !== null) return

    const nextLevel = event.target.value
    if (nextLevel === level) return

    setStatus('loading')
    setPage(1)
    setLevel(nextLevel)
  }

  function changePage(nextPage) {
    if (pendingCourseId !== null) return

    setStatus('loading')
    setPage(nextPage)
  }

  async function handleEnrollment(course) {
    if (
      !isStudent ||
      pendingCourseId !== null ||
      enrollmentStatus !== 'ready'
    ) {
      return
    }

    const enrollmentId = enrollments[course.id]

    if (
      enrollmentId &&
      !window.confirm(
        `¿Quieres darte de baja del curso #${course.id}: «${course.title}»?`,
      )
    ) {
      return
    }

    setPendingCourseId(course.id)

    try {
      if (enrollmentId) {
        await withdrawEnrollment(enrollmentId)

        setEnrollments((current) => {
          const next = { ...current }
          delete next[course.id]
          return next
        })

        notify('success', `Te has dado de baja de «${course.title}».`)
      } else {
        const enrollment = await enrollInCourse(course.id)

        setEnrollments((current) => ({
          ...current,
          [course.id]: enrollment.id,
        }))

        notify('success', `Inscripción confirmada en «${course.title}».`)
      }
    } catch (error) {
      notify(
        [404, 422].includes(error.status) ? 'warning' : 'error',
        [401, 419].includes(error.status)
          ? 'La sesión ha caducado. Vuelve a iniciar sesión.'
          : error.status === 404
            ? 'El curso o la inscripción ya no está disponible. Recarga el catálogo.'
            : error.status === 422
              ? 'Este curso no admite una nueva inscripción gratuita.'
              : 'No se pudo completar la operación. Inténtalo de nuevo.',
      )
    } finally {
      setPendingCourseId(null)
    }
  }

  return (
    <section
      aria-labelledby="courses-title"
      aria-busy={status === 'loading' || pendingCourseId !== null}
    >
      <h1 id="courses-title">Cursos</h1>

      <p className={styles.intro}>
        Descubre nuestros cursos y encuentra lo que quieres aprender.
      </p>

      <FormField
        as="select"
        id="filter-level"
        label="Nivel"
        value={level}
        onChange={handleLevelChange}
        disabled={pendingCourseId !== null}
      >
        <option value="">Todos los niveles</option>
        <option value="beginner">Principiante</option>
        <option value="advanced">Avanzado</option>
        <option value="expert">Experto</option>
      </FormField>

      {status === 'loading' && <p role="status">Cargando cursos…</p>}

      {status === 'error' && (
        <p role="alert">
          No se han podido cargar los cursos. Inténtalo de nuevo.
        </p>
      )}

      {status === 'ready' && (
        <>
          {enrollmentStatus === 'error' && (
            <p role="alert">
              No se pudieron consultar tus inscripciones. Recarga el catálogo
              para intentarlo de nuevo.
            </p>
          )}

          {courses.length === 0 ? (
            <p>
              {level
                ? 'No hay cursos con el nivel seleccionado.'
                : 'Todavía no hay cursos publicados.'}
            </p>
          ) : (
            <div className={styles.grid}>
              {courses.map((course) => (
                <CourseCard key={course.id} course={course}>
                  {isStudent &&
                    (enrollments[course.id] || Number(course.price) === 0) && (
                      <div className={styles.enrollmentActions}>
                        {enrollments[course.id] && (
                          <p>Estás inscrito en este curso.</p>
                        )}

                        <Button
                          variant={
                            enrollments[course.id] ? 'secondary' : 'primary'
                          }
                          disabled={
                            pendingCourseId !== null ||
                            enrollmentStatus !== 'ready'
                          }
                          onClick={() => handleEnrollment(course)}
                          aria-label={`${
                            enrollments[course.id]
                              ? 'Darme de baja de'
                              : 'Inscribirme en'
                          } ${course.title}`}
                        >
                          {pendingCourseId === course.id
                            ? 'Procesando…'
                            : enrollments[course.id]
                              ? 'Darme de baja'
                              : 'Inscribirme'}
                        </Button>
                      </div>
                    )}

                  {!user &&
                    authStatus === 'ready' &&
                    Number(course.price) === 0 && (
                      <div className={styles.enrollmentActions}>
                        <Link to="/account">
                          Inicia sesión para inscribirte
                        </Link>
                      </div>
                    )}
                </CourseCard>
              ))}
            </div>
          )}

          {pagination.last_page > 1 && (
            <nav
              className={styles.pagination}
              aria-label="Paginación de cursos"
            >
              <Button
                disabled={page <= 1 || pendingCourseId !== null}
                onClick={() => changePage(page - 1)}
              >
                Anterior
              </Button>

              <span>
                Página {page} de {pagination.last_page}
              </span>

              <Button
                disabled={
                  page >= pagination.last_page || pendingCourseId !== null
                }
                onClick={() => changePage(page + 1)}
              >
                Siguiente
              </Button>
            </nav>
          )}
        </>
      )}
    </section>
  )
}
