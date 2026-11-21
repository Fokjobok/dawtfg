import { useEffect, useState } from 'react'
import { Link } from 'react-router'
import Button from '../components/Button.jsx'
import { deleteCourse, getManagedCourses } from '../services/courses.js'

import styles from './ManageCoursesPage.module.scss'

const LEVEL_LABELS = {
  beginner: 'Principiante',
  advanced: 'Avanzado',
  expert: 'Experto',
}

const STATUS_LABELS = {
  draft: 'Borrador',
  published: 'Publicado',
}

export default function ManageCoursesPage() {
  const [courses, setCourses] = useState([])

  const [page, setPage] = useState(1)
  const [pagination, setPagination] = useState(null)

  const [status, setStatus] = useState('loading')

  const [deletingId, setDeletingId] = useState(null)
  const [deleteFeedback, setDeleteFeedback] = useState(null)
  const [reloadKey, setReloadKey] = useState(0)

  useEffect(() => {
    let active = true

    getManagedCourses(page)
      .then((response) => {
        if (!active) return

        //Vuelve a una página válida al borrar el último curso
        if (page > response.meta.last_page) {
          setPage(response.meta.last_page)
          return
        }

        setCourses(response.data)
        setPagination(response.meta)
        setStatus('ready')
      })
      .catch(() => {
        if (active) setStatus('error')
      })

    return () => {
      active = false
    }
  }, [page, reloadKey])

  async function handleDelete(course) {
    if (deletingId !== null || !course.can_delete) return

    const confirmed = window.confirm(
      `¿Borrar el curso #${course.id}: ${course.title}? Esta acción es definitiva.`,
    )

    if (!confirmed) return

    setDeletingId(course.id)
    setDeleteFeedback(null)

    try {
      await deleteCourse(course.id)

      setDeleteFeedback({
        error: false,
        message: `Curso #${course.id} borrado.`,
      })

      setStatus('loading')
      setReloadKey((current) => current + 1)
    } catch (error) {
      setDeleteFeedback({
        error: true,
        message:
          error.status === 403
            ? 'No tienes permiso para borrar este curso.'
            : 'No se pudo confirmar el borrado. Recarga el listado antes de reintentar.',
      })
    } finally {
      setDeletingId(null)
    }
  }

  function changePage(nextPage) {
    if (nextPage === page || deletingId !== null) return

    setStatus('loading')
    setPage(nextPage)
  }

  return (
    <section
      aria-labelledby="manage-courses-title"
      aria-busy={status === 'loading' || deletingId !== null}
    >
      <h1 id="manage-courses-title">Gestionar cursos</h1>
      <p>Consulta los cursos que puedes gestionar.</p>

      <Link to="/courses/create">Crear curso</Link>

      {deleteFeedback && (
        <p role={deleteFeedback.error ? 'alert' : 'status'}>
          {deleteFeedback.message}
        </p>
      )}

      {status === 'loading' && <p role="status">Cargando cursos…</p>}

      {status === 'error' && (
        <p role="alert">
          No se pudo cargar el listado. Comprueba tu sesión y vuelve a
          intentarlo.
        </p>
      )}

      {status === 'ready' && (
        <>
          {courses.length === 0 ? (
            <p>No hay cursos que puedas gestionar.</p>
          ) : (
            <ul>
              {courses.map((course) => (
                <li key={course.id}>
                  <article aria-labelledby={`managed-course-${course.id}`}>
                    <p>Curso #{course.id}</p>

                    {course.cover_url && (
                      <img
                        className={styles.thumbnail}
                        src={course.cover_url}
                        alt=""
                        width={192}
                        height={96}
                        loading="lazy"
                        decoding="async"
                      />
                    )}

                    <h2 id={`managed-course-${course.id}`}>{course.title}</h2>

                    <p>
                      Especialidad:{' '}
                      {course.specialty?.name ?? 'Sin especialidad'}
                    </p>
                    <p>
                      Nivel: {LEVEL_LABELS[course.level] ?? 'Sin clasificar'}
                    </p>
                    <p>Estado: {STATUS_LABELS[course.status]}</p>

                    <Link
                      to={`/courses/${course.id}/edit`}
                      aria-label={`Editar ${course.title}`}
                    >
                      Editar
                    </Link>

                    {course.can_delete && (
                      <Button
                        variant="secondary"
                        disabled={deletingId !== null}
                        onClick={() => handleDelete(course)}
                        aria-label={`Borrar curso #${course.id}: ${course.title}`}
                      >
                        {deletingId === course.id ? 'Borrando…' : 'Borrar'}
                      </Button>
                    )}
                  </article>
                </li>
              ))}
            </ul>
          )}

          {pagination.last_page > 1 && (
            <nav aria-label="Paginación de cursos gestionables">
              <Button
                disabled={page <= 1 || deletingId !== null}
                onClick={() => changePage(page - 1)}
              >
                Anterior
              </Button>

              <span>
                {' '}
                Página {page} de {pagination.last_page}{' '}
              </span>

              <Button
                disabled={page >= pagination.last_page || deletingId !== null}
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
