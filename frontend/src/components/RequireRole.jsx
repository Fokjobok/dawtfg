import { useEffect, useState } from 'react'
import { Navigate, Outlet } from 'react-router'
import { getSession } from '../services/auth.js'

export default function RequireRole({ role }) {
  const [user, setUser] = useState(null)
  const [status, setStatus] = useState('loading')

  useEffect(() => {
    let active = true

    getSession()
      .then((session) => {
        if (!active) return

        setUser(session)
        setStatus('ready')
      })
      .catch(() => {
        if (active) setStatus('error')
      })

    return () => {
      active = false
    }
  }, [])

  if (status === 'loading') {
    return <p role="status">Comprobando la sesión…</p>
  }

  if (status === 'error') {
    return <p role="alert">No se pudo comprobar la sesión.</p>
  }

  if (!user) {
    return <Navigate to="/account" replace />
  }

  const allowedRoles = Array.isArray(role) ? role : [role]

  if (!allowedRoles.includes(user.role)) {
    return <Navigate to="/" replace />
  }

  return <Outlet />
}
