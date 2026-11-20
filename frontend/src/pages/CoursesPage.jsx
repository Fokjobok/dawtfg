import { useEffect, useState } from 'react'
import Button from '../components/Button.jsx'
import CourseCard from '../components/CourseCard.jsx'
import { getCourses } from '../services/courses.js'
import styles from './CoursesPage.module.scss'
import FormField from '../components/FormField.jsx'

export default function CoursesPage() {
  const [courses, setCourses] = useState([])
  const [page, setPage] = useState(1)
  const [pagination, setPagination] = useState(null)
  const [status, setStatus] = useState('loading')
  const [level, setLevel] = useState('')

  useEffect(() => {
    let active = true

    getCourses(page, { level })
      .then((response) => {
        if (!active) return

        setCourses(response.data)
        setPagination(response.meta)
        setStatus('ready')
      })
      .catch(() => {
        if (!active) return

        setStatus('error')
      })

    return () => {
      active = false
    }
  }, [page, level])

  function handleLevelChange(event) {
    const nextLevel = event.target.value

    if (nextLevel === level) return

    setStatus('loading')
    setPage(1)
    setLevel(nextLevel)
  }

  function changePage(nextPage) {
    setStatus('loading')
    setPage(nextPage)
  }

  return (
    <section aria-labelledby="courses-title" aria-busy={status === 'loading'}>
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
      >
        <option value="">Todos los niveles</option>
        <option value="beginner">Principiante</option>
        <option value="advanced">Avanzado</option>
        <option value="expert">Experto</option>
      </FormField>

      {status === 'loading' && <p role="status">Cargando cursos…</p>}

      {status === 'error' && (
        <p role="alert">
          No se han podido cargar los cursos. Inténtalo de nuevo más tarde.
        </p>
      )}

      {status === 'ready' && (
        <>
          {courses.length === 0 ? (
            <p>
              {level
                ? 'No hay cursos con el nivel seleccionado.'
                : 'Todavía no hay cursos publicados.'}
            </p>
          ) : (
            <div className={styles.grid}>
              {courses.map((course) => (
                <CourseCard key={course.id} course={course} />
              ))}
            </div>
          )}

          {pagination.last_page > 1 && (
            <nav
              className={styles.pagination}
              aria-label="Paginación de cursos"
            >
              <Button disabled={page <= 1} onClick={() => changePage(page - 1)}>
                Anterior
              </Button>

              <span>
                Página {page} de {pagination.last_page}
              </span>

              <Button
                disabled={page >= pagination.last_page}
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
