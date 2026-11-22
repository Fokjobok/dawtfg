import { Navigate, Outlet } from 'react-router'
import { useAuth } from '../context/AuthContext.jsx'

export default function RequireRole({ role }) {
  const { user, status } = useAuth()

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
