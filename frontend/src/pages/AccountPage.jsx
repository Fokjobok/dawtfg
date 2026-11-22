import { useEffect, useRef, useState } from 'react'
import Button from '../components/Button.jsx'
import FormField from '../components/FormField.jsx'
import { ApiError } from '../services/api.js'

import { login, logout, register } from '../services/auth.js'
import { useAuth } from '../context/AuthContext.jsx'

import { Link, useNavigate } from 'react-router'

import styles from './AccountPage.module.scss'

export default function AccountPage() {
  const { user, setUser, status } = useAuth()
  const [pending, setPending] = useState(false)
  const [mode, setMode] = useState('login')
  const [message, setMessage] = useState('')
  const [errors, setErrors] = useState({})
  const formRef = useRef(null)

  const navigate = useNavigate()
  const [loggingOut, setLoggingOut] = useState(false)
  const [logoutError, setLogoutError] = useState('')

  useEffect(() => {
    formRef.current?.querySelector('input')?.focus()
  }, [mode])

  useEffect(() => {
    formRef.current?.querySelector('[aria-invalid="true"]')?.focus()
  }, [errors])

  function showError(error) {
    setErrors(error instanceof ApiError ? error.errors : {})
    if (error.status === 419 || error.status === 401) {
      setUser(null)
      setMode('login')
      setMessage('La sesión ha caducado. Inicia sesión de nuevo.')
    } else if (error.status === 429) {
      setMessage(
        'Demasiados intentos. Espera un minuto antes de volver a intentarlo.',
      )
    } else if (error.status === 422) {
      setMessage('Revisa los datos del formulario.')
    } else {
      setMessage('No se pudo completar la solicitud. Comprueba la conexión.')
    }
  }

  async function submit(event) {
    event.preventDefault()
    const form = event.currentTarget
    const details = Object.fromEntries(new FormData(form))
    setPending(true)
    setMessage('')
    setErrors({})
    try {
      setUser(await (mode === 'register' ? register(details) : login(details)))
      form.reset()
    } catch (error) {
      showError(error)
    } finally {
      setPending(false)
    }
  }

  async function handleLogout() {
    if (loggingOut) return

    setLoggingOut(true)
    setLogoutError('')

    try {
      await logout()
      setUser(null)
      navigate('/account', { replace: true })
    } catch (error) {
      if (error.status === 401) {
        setUser(null)
        navigate('/account', { replace: true })
      } else {
        setLogoutError(
          'No se pudo confirmar el cierre de sesión. Inténtalo de nuevo.',
        )
      }
    } finally {
      setLoggingOut(false)
    }
  }

  if (status === 'loading') {
    return <p role="status">Consultando la sesión…</p>
  }

  if (status === 'error') {
    return (
      <p role="alert">
        No se pudo consultar la sesión. Recarga la página e inténtalo de nuevo.
      </p>
    )
  }

  return (
    <section className={styles.grid} aria-labelledby="account-title">
      <div className={styles.intro}>
        <p className={styles.eyebrow}>Tu espacio de formación</p>
        <h1 id="account-title">Mi cuenta</h1>
        <p>
          {user
            ? 'Consulta tus datos y gestiona tu sesión.'
            : 'Accede a tu cuenta o crea una cuenta de estudiante para empezar.'}
        </p>
        <p className={styles.note}>
          Conserva el control de tu sesión. Puedes cerrarla cuando termines.
        </p>
      </div>
      <div className={styles.panel}>
        {message && (
          <p className={styles.notice} role="status">
            {message}
          </p>
        )}
        {user ? (
          <>
            <div className={styles.profileHeader}>
              <h2>Mi perfil</h2>

              {user.avatar_url ? (
                <img
                  className={styles.avatar}
                  src={user.avatar_url}
                  alt={`Avatar de ${user.name}`}
                  width={128}
                  height={128}
                  decoding="async"
                />
              ) : (
                <span className={styles.avatarPlaceholder}>Sin avatar</span>
              )}
            </div>
            <dl className={styles.identity}>
              <div>
                <dt>Nombre</dt>
                <dd>{user.name}</dd>
              </div>
              <div>
                <dt>Correo electrónico</dt>
                <dd>{user.email}</dd>
              </div>
              <div>
                <dt>Rol</dt>
                <dd>{user.role}</dd>
              </div>
            </dl>

            <div className={styles.actions}>
              <Link to={`/profiles/${user.id}`}>Editar perfil</Link>

              {['Coach', 'Administrator'].includes(user.role) && (
                <Link to="/courses/manage">Gestionar cursos</Link>
              )}
            </div>

            <div className={styles.actions}>
              <Button
                variant="secondary"
                onClick={handleLogout}
                disabled={loggingOut}
                aria-busy={loggingOut}
              >
                {loggingOut ? 'Cerrando…' : 'Cerrar sesión'}
              </Button>
            </div>

            {logoutError && <p role="alert">{logoutError}</p>}
          </>
        ) : (
          <>
            <h2>
              {mode === 'register'
                ? 'Crear cuenta de estudiante'
                : 'Iniciar sesión'}
            </h2>
            <form
              ref={formRef}
              className={styles.form}
              onSubmit={submit}
              aria-busy={pending}
            >
              {mode === 'register' && (
                <FormField
                  id="name"
                  label="Nombre"
                  autoComplete="name"
                  required
                  maxLength={100}
                  errors={errors.name}
                />
              )}
              <FormField
                id="email"
                label="Correo electrónico"
                type="email"
                autoComplete="username"
                required
                maxLength={254}
                errors={errors.email}
              />
              <FormField
                id="password"
                label="Contraseña"
                hint={
                  mode === 'register'
                    ? 'Mínimo 12 caracteres y máximo 72 bytes. Algunos caracteres ocupan varios bytes.'
                    : undefined
                }
                type="password"
                autoComplete={
                  mode === 'register' ? 'new-password' : 'current-password'
                }
                required
                minLength={mode === 'register' ? 12 : undefined}
                maxLength={72}
                errors={errors.password}
              />
              {mode === 'register' && (
                <FormField
                  id="password_confirmation"
                  label="Repetir contraseña"
                  type="password"
                  autoComplete="new-password"
                  required
                  maxLength={72}
                  errors={errors.password_confirmation}
                />
              )}
              <Button type="submit" disabled={pending}>
                {pending
                  ? 'Enviando…'
                  : mode === 'register'
                    ? 'Crear cuenta'
                    : 'Entrar'}
              </Button>
            </form>
            <Button
              variant="ghost"
              type="button"
              disabled={pending}
              onClick={() => {
                setMode(mode === 'register' ? 'login' : 'register')
                setErrors({})
                setMessage('')
              }}
            >
              {mode === 'register'
                ? 'Ya tengo cuenta'
                : 'Crear cuenta de estudiante'}
            </Button>
          </>
        )}
      </div>
    </section>
  )
}
